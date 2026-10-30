<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
type Stream = App.Data.StreamData
type Video = App.Data.VideoData
type VideoStatus = App.Enums.VideoStatus
type Output = App.Data.OutputData
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RouterLink, useRoute } from 'vue-router'
import VideoService from '@/services/VideoService'
import { formatBytes } from '@/utils/byteFormatter'
import StorageSize from '@/components/StorageSize.vue'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import StreamService from '@/services/StreamService'
import { EditIcon, FileVideo, Radio, Box, Subtitles, PlayIcon, Clock, HardDrive, CalendarDays, Trash2, Plus } from '@lucide/vue'
import { formatSecondsToTime } from '@/utils/timeFormatter'
import DeleteVideoButton from './components/DeleteVideoButton.vue'
import RetryVideoButton from './components/RetryVideoButton.vue'
import StreamItem from './components/StreamItem.vue'
import EditStreamDialog from './components/EditStreamDialog.vue'
import AddTrackDialog from './components/AddTrackDialog.vue'
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'
import ShakaVideoPlayer from './components/ShakaVideoPlayer.vue'
import { useCodecConfig } from '@/composables/useCodecConfig'

const route = useRoute()
const { config: codecConfig, fetchConfig: fetchCodecConfig } = useCodecConfig()

const video = ref<Video>()
const isEditDialogOpen = ref(false)
const editName = ref('')
const editExternalUserId = ref('')
const editExternalResourceId = ref('')
const playerOutput = ref<Output | null>(null)

const codecLabel = (codec?: string | null): string | null => {
  if (!codec) return null
  return codecConfig.value?.codecs.find(c => c.codec === codec)?.label ?? codec
}

const outputCodecs = (output: Output): string[] => {
  const codecs = new Set<string>()
  for (const s of output.streams) {
    const raw = s.type === 'video' ? s.inputParams?.video_codec : s.type === 'audio' ? s.inputParams?.audio_codec : null
    const label = codecLabel(raw as string | undefined)
    if (label) codecs.add(label)
  }
  return Array.from(codecs)
}

const loading = ref(true)
const loadError = ref<string | null>(null)

const show = async () => {
  video.value = await VideoService.show(route.params.id!.toString())
}

/** The initial load and the Retry button: the only places allowed to paint an error screen. */
const load = async () => {
  loading.value = true
  loadError.value = null

  try {
    await show()
  } catch (error) {
    // Without this the page rendered nothing at all — a deleted video, a video of another
    // project or a momentary API hiccup all looked identical to a blank screen.
    loadError.value = error instanceof ApiException ? error.message : 'Could not load this video.'
  } finally {
    loading.value = false
  }
}

const formatDate = (dateString: string | null): string => {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleString()
}

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline'

const getStatusVariant = (status: VideoStatus): BadgeVariant => {
  const variants: Record<VideoStatus, BadgeVariant> = {
    pending: 'secondary',
    running: 'default',
    completed: 'default',
    failed: 'destructive',
    downloading: 'default',
    uploading: 'default',
  }
  return variants[status] || 'default'
}

const editStreamDialog = ref<InstanceType<typeof EditStreamDialog>>()

/**
 * A subtitle is the very same object in every output card (`outputStreams` concatenates the
 * video-level list), so replacing it in `video.streams` refreshes all of them at once. An audio
 * track is serialized per output, so each `output.streams` copy has to be replaced too.
 */
function onStreamUpdated(updated: Stream) {
  const replace = (streams?: Stream[]) => {
    const idx = streams?.findIndex(s => s.ulid === updated.ulid) ?? -1
    if (idx !== -1) streams?.splice(idx, 1, updated)
  }

  replace(video.value?.streams)
  video.value?.outputs.forEach(output => replace(output.streams))
}

function onStreamDeleted(stream: Stream) {
  video.value?.outputs.forEach(output => {
    const idx = output.streams.findIndex(s => s.ulid === stream.ulid)
    if (idx !== -1) output.streams.splice(idx, 1)
  })
  const vidIdx = video.value?.streams.findIndex(s => s.ulid === stream.ulid) ?? -1
  if (vidIdx !== -1) video.value?.streams.splice(vidIdx, 1)
}

const handleEdit = () => {
  editName.value = video.value?.name ?? ''
  editExternalUserId.value = video.value?.externalUserId ?? ''
  editExternalResourceId.value = video.value?.externalResourceId ?? ''
  isEditDialogOpen.value = true
}

const handleUpdate = async () => {
  if (!video.value) return
  try {
    const res = await VideoService.update(video.value.ulid, {
      name: editName.value,
      externalUserId: editExternalUserId.value.trim() || null,
      externalResourceId: editExternalResourceId.value.trim() || null,
    })
    toast.success(res.data.message)
    video.value = res.data.data
    isEditDialogOpen.value = false
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  }
}

/** Everything `size` counts beyond the streamed packages: kept renditions and the source. */
const retainedSize = computed(() => (video.value?.size ?? 0) - (video.value?.servedSize ?? 0))

const formatLabel: Record<string, string> = {
  hls: 'HLS',
  dash: 'DASH',
}

const formatIcon: Record<string, typeof Radio> = {
  hls: Radio,
  dash: Box,
}

const sumStreams = (output: Output, key: 'packageSize' | 'fileSize'): number =>
  output.streams.reduce((sum, s) => sum + (s[key] ?? 0), 0)

const subtitleStreams = computed(() =>
  video.value?.streams.filter(s => s.type === 'subtitle') ?? []
)

/** The uploaded source. Present while processing, and afterwards only if the template keeps it. */
const originalStream = computed(() =>
  video.value?.streams.find(s => s.type === 'original')
)

const isDeleteSourceOpen = ref(false)

// The API rejects deleting or editing any stream of a video that is still being processed.
const canManageStreams = computed(() =>
  !!video.value && terminalStatuses.includes(video.value.status)
)

/** What `VideoService::DELETABLE_STATUSES` allows: the terminal two, plus a pending video —
 *  nothing has started on that one, so there is no run to strand. */
const canDelete = computed(() =>
  !!video.value && (video.value.status === 'pending' || terminalStatuses.includes(video.value.status))
)

const deleteSource = async () => {
  const stream = originalStream.value
  if (!stream) return

  try {
    const res = await StreamService.destroy(stream.ulid)
    toast.info(res.data.message)
    onStreamDeleted(stream)
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  } finally {
    isDeleteSourceOpen.value = false
  }
}

const outputStreams = (output: Output): Stream[] => [
  ...output.streams,
  ...subtitleStreams.value,
]

const getOutputSummary = (output: Output): string => {
  const resolutions = output.streams
    .filter(s => s.type === 'video' && s.height)
    // The short edge, as StreamItem labels it: a vertical 1080x1920 rendition is 1080p.
    .map(s => Math.min(s.height!, s.width ?? s.height!))
    .sort((a, b) => b - a)
    .map(r => `${r}p`)

  const audioCount = output.streams.filter(s => s.type === 'audio').length

  const parts: string[] = []
  if (resolutions.length) parts.push(resolutions.join(', '))
  if (audioCount) parts.push(`${audioCount} audio`)
  if (subtitleStreams.value.length) parts.push(`${subtitleStreams.value.length} subtitle`)
  return parts.join(' · ')
}

const terminalStatuses: App.Enums.VideoStatus[] = ['completed', 'failed']

/**
 * A finished upload only becomes a stream once the bucket webhook lands, a few seconds later, so
 * the poll is kept alive for a while after one even though nothing is processing yet.
 */
let awaitingTrackUntil = 0

const isStillProcessing = computed(() => {
  if (!video.value) return false
  if (!terminalStatuses.includes(video.value.status as VideoStatus)) return true
  if (video.value.streams.some(s => s.status === 'processing')) return true
  return video.value.outputs.some(o => !terminalStatuses.includes(o.status))
})

const shouldPoll = () => isStillProcessing.value || Date.now() < awaitingTrackUntil

let refreshInterval: number | null = null

/** Consecutive poll failures tolerated before giving up, so a deleted video or a restarting API
 *  stops the loop instead of being hammered every 5s for as long as the tab stays open. */
const MAX_POLL_FAILURES = 3

let pollFailures = 0

const stopPolling = () => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
    refreshInterval = null
  }
}

/**
 * Re-evaluates the condition on every tick. It used to be checked once at mount, so a video that
 * finished kept being refetched for the lifetime of the tab, and a failing request produced an
 * unhandled rejection every 5 seconds.
 */
const poll = async () => {
  try {
    await show()
    pollFailures = 0

    if (!shouldPoll()) stopPolling()
  } catch {
    pollFailures++

    if (pollFailures >= MAX_POLL_FAILURES) stopPolling()
  }
}

/** Idempotent, so it can be called from the initial mount and from a Retry alike. */
const startPolling = () => {
  stopPolling()
  pollFailures = 0

  if (video.value && shouldPoll()) {
    refreshInterval = setInterval(poll, 5000)
  }
}

/** A requeued video comes back PENDING and starts moving again, so the poll has to be re-armed:
 *  it stops itself the moment a video reaches a terminal status, which is where this one was. */
const onRetried = (updated: Video) => {
  video.value = updated
  startPolling()
}

const addTrackDialog = ref<InstanceType<typeof AddTrackDialog>>()

const onTrackAdded = async () => {
  awaitingTrackUntil = Date.now() + 60_000
  await show()
  startPolling()
}

/**
 * An added audio track joins its outputs only once it is published, so until then — and for good
 * if it fails — it belongs to no output card. Subtitles need no such list: every output card
 * shows them all already.
 */
const pendingAudioTracks = computed(() =>
  video.value?.streams.filter(s => s.type === 'audio' && (s.status === 'processing' || s.status === 'failed')) ?? []
)

/** Retry from the error screen: load again and re-arm the poll if the video is still working. */
const reload = async () => {
  await load()
  startPolling()
}

onMounted(async () => {
  fetchCodecConfig()
  await load()
  startPolling()
})

onUnmounted(stopPolling)
</script>

<template>
  <div v-if="loading" class="container mx-auto p-6">
    <p class="text-sm text-muted-foreground">Loading video…</p>
  </div>

  <div v-else-if="loadError" class="container mx-auto p-6 space-y-3">
    <p class="text-sm text-destructive">{{ loadError }}</p>
    <Button variant="outline" size="sm" @click="reload">Retry</Button>
  </div>

  <div v-else-if="video" class="container mx-auto p-6 space-y-6">

    <!-- Video Information -->
    <Card>
      <CardHeader>
        <div class="flex items-start justify-between gap-2">
          <div class="space-y-1 flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <CardTitle class="text-xl font-bold">{{ video.name }}</CardTitle>
              <Button variant="ghost" size="icon" @click="handleEdit" class="h-8 w-8">
                <EditIcon :size="16" />
              </Button>
            </div>
            <CardDescription class="text-xs break-all">{{ video.ulid }}</CardDescription>
          </div>
          <div class="flex items-center gap-2">
            <!-- The only way back into the pipeline: dispatch only ever picks up a PENDING video,
                 and nothing puts a failed one back there on its own. -->
            <RetryVideoButton v-if="video.status === 'failed'" :id="video.ulid" @retried="onRetried" />
            <Button v-if="video.status === 'completed'" variant="outline" size="sm" @click="addTrackDialog?.show()">
              <Plus :size="14" class="mr-1" /> Add track
            </Button>
            <!-- Disabled rather than hidden: the API refuses the same set, and a button that
                 vanishes mid-encode reads as a missing feature. -->
            <DeleteVideoButton :id="video.ulid" :disabled="!canDelete" />
          </div>
        </div>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Status</span>
          <Badge :variant="getStatusVariant(video.status)">{{ video.status }}</Badge>
        </div>
        <div class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Duration</span>
          <div class="flex items-center gap-1.5">
            <Clock :size="14" class="text-muted-foreground" />
            <span class="text-sm font-semibold">{{ formatSecondsToTime(video.duration) }}</span>
          </div>
        </div>
        <div class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Size</span>
          <div class="flex items-center gap-1.5">
            <HardDrive :size="14" class="text-muted-foreground" />
            <StorageSize class="text-sm font-semibold" :served="video.servedSize" :retained="retainedSize" />
          </div>
        </div>
        <div v-if="originalStream" class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Source</span>
          <div class="flex items-center gap-1.5">
            <FileVideo :size="14" class="text-muted-foreground" />
            <span class="text-sm font-semibold">{{ formatBytes(originalStream.fileSize) }}</span>
            <Button
              v-if="canManageStreams"
              variant="ghost"
              size="icon"
              class="h-6 w-6 text-destructive"
              title="Delete source file"
              @click="isDeleteSourceOpen = true"
            >
              <Trash2 :size="13" />
            </Button>
          </div>
        </div>
        <div v-if="video.template" class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Template</span>
          <!-- The version it was encoded with, which later edits to the template do not change. -->
          <RouterLink :to="`/templates/${video.template.ulid}`" class="flex items-center gap-1.5 text-sm font-semibold hover:underline truncate ml-2">
            {{ video.template.name }}
            <Badge v-if="video.template.version" variant="outline" class="text-[10px] px-1.5 py-0 font-normal">v{{ video.template.version }}</Badge>
          </RouterLink>
        </div>
        <div class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Created</span>
          <div class="flex items-center gap-1.5">
            <CalendarDays :size="14" class="text-muted-foreground" />
            <span class="text-sm font-semibold">{{ formatDate(video.createdAt) }}</span>
          </div>
        </div>
        <div v-if="video.externalUserId" class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">External User ID</span>
          <span class="text-sm font-semibold truncate ml-2">{{ video.externalUserId }}</span>
        </div>
        <div v-if="video.externalResourceId" class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">External Resource ID</span>
          <span class="text-sm font-semibold truncate ml-2">{{ video.externalResourceId }}</span>
        </div>
        <div v-if="subtitleStreams.length > 0" class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Subtitles</span>
          <div class="flex items-center gap-1.5">
            <Subtitles :size="14" class="text-muted-foreground" />
            <span class="text-sm font-semibold">{{ subtitleStreams.length }} tracks</span>
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Delete Source Dialog -->
    <AlertDialog v-model:open="isDeleteSourceOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Delete source file</AlertDialogTitle>
          <AlertDialogDescription>
            Delete the uploaded source and free its storage? Playback is unaffected, but the video
            can no longer be re-encoded. This action cannot be undone.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction @click="deleteSource">Delete</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Video Player Dialog -->
    <Dialog :open="!!playerOutput" :modal="false" @update:open="(v: boolean) => { if (!v) playerOutput = null }">
      <DialogContent class="sm:max-w-4xl p-0 overflow-hidden" @pointer-down-outside.prevent @interact-outside.prevent>
        <DialogHeader class="p-6 pb-0">
          <div class="flex items-center gap-2">
            <component v-if="playerOutput" :is="FileVideo" :size="18" class="text-muted-foreground" />
            <DialogTitle>{{ playerOutput ? playerOutput.formats.map(f => formatLabel[f] ?? f).join(' / ') : '' }} Player</DialogTitle>
          </div>
        </DialogHeader>
        <div class="px-6 pb-6">
          <!-- Keyed on the output: the dialog is non-modal, so another output's play button is
               reachable while it is open, and without a key the player instance was reused and
               kept playing the first output under the new title. -->
          <ShakaVideoPlayer v-if="playerOutput" :key="playerOutput.ulid" :video="video"
            :output-ulid="playerOutput.ulid" />
        </div>
      </DialogContent>
    </Dialog>

    <!-- Outputs Section -->
    <div v-if="video.outputs.length > 0" class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold">Outputs</h2>
        <Badge variant="outline" class="text-sm">{{ video.outputs.length }} Total</Badge>
      </div>

      <div class="space-y-3">
        <Card v-for="output in video.outputs" :key="output.ulid">
          <CardHeader class="pb-3">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-1.5 min-w-0 flex-wrap">
                <Badge v-for="f in output.formats" :key="f" variant="secondary" class="text-xs gap-1">
                  <component :is="formatIcon[f] ?? FileVideo" :size="12" />
                  {{ formatLabel[f] ?? f }}
                </Badge>
                <Badge :variant="getStatusVariant(output.status)" class="text-xs">{{ output.status }}</Badge>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <StorageSize
                  class="text-sm text-muted-foreground"
                  :served="sumStreams(output, 'packageSize')"
                  :retained="sumStreams(output, 'fileSize')"
                />
                <Button
                  v-if="output.status === 'completed'"
                  variant="ghost"
                  size="icon"
                  class="h-7 w-7"
                  @click="playerOutput = output"
                >
                  <PlayIcon :size="14" />
                </Button>
              </div>
            </div>

            <!-- Summary line -->
            <p v-if="getOutputSummary(output)" class="text-xs text-muted-foreground mt-2">
              {{ getOutputSummary(output) }}
            </p>

            <!-- Codecs -->
            <div v-if="outputCodecs(output).length" class="flex flex-wrap gap-1 mt-1.5">
              <Badge v-for="c in outputCodecs(output)" :key="c" variant="outline" class="text-[10px] px-1.5 py-0 font-normal text-muted-foreground">
                {{ c }}
              </Badge>
            </div>

            <!-- Progress (only while running) -->
            <div v-if="output.status === 'running'" class="flex items-center gap-2 mt-2">
              <Progress :model-value="output.progress" class="h-1.5 flex-1" />
              <span class="text-xs text-muted-foreground tabular-nums">{{ output.progress }}%</span>
            </div>
          </CardHeader>

          <CardContent class="pt-0 pb-0">
            <div v-if="outputStreams(output).length" class="border rounded-md divide-y overflow-hidden mb-4">
              <StreamItem
                v-for="stream in outputStreams(output)"
                :key="stream.ulid"
                :stream="stream"
                :codec-label="codecLabel"
                :editable="canManageStreams"
                :downloadable="video.status === 'completed'"
                @on-deleted="() => onStreamDeleted(stream)"
                @on-edit="s => editStreamDialog?.show(s)"
              />
            </div>
          </CardContent>
        </Card>
      </div>
    </div>

    <!-- Added audio tracks that belong to no output yet (processing) or never will (failed). -->
    <Card v-if="pendingAudioTracks.length > 0">
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Added tracks</CardTitle>
      </CardHeader>
      <CardContent class="pt-0">
        <div class="border rounded-md divide-y overflow-hidden">
          <StreamItem
            v-for="stream in pendingAudioTracks"
            :key="stream.ulid"
            :stream="stream"
            :codec-label="codecLabel"
            :editable="canManageStreams"
            :downloadable="false"
            @on-deleted="() => onStreamDeleted(stream)"
          />
        </div>
      </CardContent>
    </Card>

    <EditStreamDialog ref="editStreamDialog" @updated="onStreamUpdated" />

    <AddTrackDialog ref="addTrackDialog" :video-ulid="video.ulid" @added="onTrackAdded" />

    <!-- Edit Video Dialog -->
    <Dialog v-model:open="isEditDialogOpen">
      <DialogContent class="sm:max-w-106.25">
        <DialogHeader>
          <DialogTitle>Edit Video</DialogTitle>
        </DialogHeader>
        <div class="grid gap-4 py-4">
          <div class="grid grid-cols-4 items-center gap-4">
            <Label for="name" class="text-right">Name</Label>
            <Input id="name" v-model="editName" class="col-span-3" @keyup.enter="handleUpdate" />
          </div>
          <div class="grid grid-cols-4 items-center gap-4">
            <Label for="external-user-id" class="text-right">External User ID</Label>
            <Input
              id="external-user-id"
              v-model="editExternalUserId"
              class="col-span-3"
              placeholder="Optional"
              @keyup.enter="handleUpdate"
            />
          </div>
          <div class="grid grid-cols-4 items-center gap-4">
            <Label for="external-resource-id" class="text-right">External Resource ID</Label>
            <Input
              id="external-resource-id"
              v-model="editExternalResourceId"
              class="col-span-3"
              placeholder="Optional"
              @keyup.enter="handleUpdate"
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="isEditDialogOpen = false">Cancel</Button>
          <Button @click="handleUpdate">Save changes</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

  </div>
</template>

<style scoped>
.container {
  max-width: 1400px;
}
</style>
