<script setup lang="ts">
import { computed, ref } from 'vue'
import { Button } from '@/components/ui/button'
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { ApiException } from '@/exceptions/ApiException'
import StreamService from '@/services/StreamService'
type Stream = App.Data.StreamData
import { MoreVertical, Pencil, Trash2, Video, Music, Subtitles, ChevronDown, Asterisk, Download } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Spinner } from '@/components/ui/spinner'
import StorageSize from '@/components/StorageSize.vue'
import { toast } from 'vue-sonner'

const { stream, codecLabel, editable, downloadable } = defineProps<{
  stream: Stream
  /** Resolves an ffmpeg codec name (e.g. `libx264`) to its display label; falls back to the raw name. */
  codecLabel: (codec?: string | null) => string | null
  /** False while the video is still processing — the API rejects edits until then. */
  editable?: boolean
  /** False until the video is completed — the API only mints download links after that. */
  downloadable?: boolean
}>()
const emit = defineEmits<{ onDeleted: []; onEdit: [stream: Stream] }>()

const isErrorExpanded = ref(false)
const isDeleteDialogOpen = ref(false)
const isForced = computed(() => stream.type === 'subtitle' && stream.forced)

// Only audio and text tracks carry a name, language or forced flag in a manifest.
// A track still being added is locked until it is published: the API answers 409.
const isProcessing = computed(() => stream.status === 'processing')
const isFailed = computed(() => stream.status === 'failed')

const canEdit = computed(() => editable && !isProcessing.value && !isFailed.value && ['audio', 'subtitle'].includes(stream.type))

// A null fileSize means the raw track was not retained (the template dropped processed files),
// so there is no object to hand out and the API would answer 404. `original` is never
// downloadable through this endpoint, but it also never renders through this component.
const canDownload = computed(() => downloadable && stream.fileSize != null)

const isDownloading = ref(false)

const handleDownload = async () => {
  if (isDownloading.value) return
  isDownloading.value = true
  try {
    const link = await StreamService.download(stream.ulid)
    // An anchor click rather than window.open: opening a window after an await trips popup
    // blockers, while a programmatic click on a same-document anchor does not.
    const a = document.createElement('a')
    a.href = link.url
    a.download = link.filename
    a.rel = 'noopener'
    document.body.appendChild(a)
    a.click()
    a.remove()
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  } finally {
    isDownloading.value = false
  }
}

const handleDelete = async () => {
  try {
    const res = await StreamService.destroy(stream.ulid)
    toast.info(res.data.message)
    emit('onDeleted')
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  } finally {
    isDeleteDialogOpen.value = false
  }
}

const typeIcon = computed(() => {
  if (stream.type === 'video') return Video
  if (stream.type === 'audio') return Music
  return Subtitles
})

const codec = computed((): string | null => {
  if (stream.type === 'video') return (stream.inputParams?.video_codec as string) ?? null
  if (stream.type === 'audio') return (stream.inputParams?.audio_codec as string) ?? null
  return null
})

const codecText = computed(() => codecLabel(codec.value))

const formatChannels = (ch: number): string => {
  if (ch === 1) return 'Mono'
  if (ch === 2) return 'Stereo'
  if (ch === 6) return '5.1'
  if (ch === 8) return '7.1'
  return `${ch}ch`
}

const label = computed(() => {
  // The short edge: a vertical 1080x1920 rendition is 1080p, not 1920p.
  if (stream.type === 'video') {
    return stream.height ? `${Math.min(stream.height, stream.width ?? stream.height)}p` : 'Video'
  }
  return stream.name
})

const details = computed(() => {
  const parts: string[] = []
  if (codecText.value) parts.push(codecText.value)
  if (stream.type === 'video' && stream.width && stream.height) parts.push(`${stream.width}×${stream.height}`)
  if (stream.type === 'audio' && stream.channels) parts.push(formatChannels(stream.channels))
  if (stream.language && stream.type !== 'video') parts.push(stream.language.toUpperCase())
  return parts
})
</script>

<template>
  <div>
    <div
      class="flex items-center justify-between px-3 py-2.5 gap-3"
      :class="{ 'bg-destructive/5': stream.errorLog }"
    >
      <div class="flex items-center gap-2.5 min-w-0">
        <component :is="typeIcon" :size="15" class="text-muted-foreground shrink-0" />
        <div class="min-w-0">
          <div class="flex items-center gap-1.5">
            <span class="text-sm font-medium leading-tight">{{ label }}</span>
            <Asterisk v-if="isForced" :size="13" class="text-amber-500 shrink-0" title="Forced subtitle" />
            <Badge v-if="isProcessing" variant="secondary" class="text-[10px] px-1.5 py-0 gap-1">
              <Spinner class="size-3" /> Processing
            </Badge>
            <Badge v-else-if="isFailed" variant="destructive" class="text-[10px] px-1.5 py-0">Failed</Badge>
          </div>
          <div v-if="details.length" class="text-xs text-muted-foreground mt-0.5">
            <span v-for="(d, i) in details" :key="i">
              <span v-if="i > 0" class="mx-1 opacity-30">·</span>{{ d }}
            </span>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-1.5 shrink-0">
        <StorageSize
          class="text-xs text-muted-foreground"
          :served="stream.packageSize"
          :retained="stream.fileSize"
        />

        <Button
          v-if="stream.errorLog"
          variant="ghost"
          size="icon"
          class="h-7 w-7 text-destructive"
          @click="isErrorExpanded = !isErrorExpanded"
        >
          <ChevronDown :size="14" :class="{ 'rotate-180': isErrorExpanded }" class="transition-transform" />
        </Button>

        <DropdownMenu v-if="!isProcessing">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="h-7 w-7">
              <MoreVertical :size="14" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem v-if="canEdit" @click="emit('onEdit', stream)">
              <Pencil :size="14" class="mr-2" /> Edit
            </DropdownMenuItem>
            <DropdownMenuItem v-if="canDownload" :disabled="isDownloading" @click="handleDownload">
              <Download :size="14" class="mr-2" /> Download
            </DropdownMenuItem>
            <DropdownMenuItem @click="isDeleteDialogOpen = true" class="text-destructive">
              <Trash2 :size="14" class="mr-2" /> Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>

    <div v-if="stream.errorLog && isErrorExpanded" class="px-3 pb-2.5">
      <pre class="text-xs font-mono text-destructive bg-destructive/10 border border-destructive/20 rounded p-2 whitespace-pre-wrap break-all">{{ stream.errorLog }}</pre>
    </div>
  </div>

  <AlertDialog v-model:open="isDeleteDialogOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Delete stream</AlertDialogTitle>
        <AlertDialogDescription>
          Delete stream "{{ stream.name ?? stream.ulid }}"? This action cannot be undone.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction @click="handleDelete">Delete</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
