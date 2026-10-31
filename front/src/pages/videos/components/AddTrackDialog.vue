<script setup lang="ts">
import { computed, markRaw, onUnmounted, ref } from 'vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
import { Switch } from '@/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { createS3Uppy, refreshS3UppyCsrf } from '@/stores/upload'
import { useProjectsStore } from '@/stores/projects'
import { toast } from 'vue-sonner'

type TrackType = 'audio' | 'subtitle'

const { videoUlid } = defineProps<{ videoUlid: string }>()
const emit = defineEmits<{ added: [] }>()

/** Mirrors the API's allowlist (TrackIngestService::EXTENSIONS) to pre-fill the type and the file picker; the server has the last word. */
const EXTENSIONS: Record<TrackType, string[]> = {
  audio: [
    'wav',
    'mp3',
    'm4a',
    'aac',
    'flac',
    'ogg',
    'opus',
    'mka',
    'mp4',
    'mkv',
    'mov',
    'webm',
    'ac3',
    'eac3',
  ],
  subtitle: ['srt', 'vtt', 'ass', 'ssa'],
}

const projectsStore = useProjectsStore()

const open = ref(false)
const file = ref<File | null>(null)
const type = ref<TrackType>('subtitle')
const form = ref({
  name: '',
  language: '',
  forced: false,
  hearingImpaired: false,
  visualImpaired: false,
})
const progress = ref(0)
const uploading = ref(false)
const error = ref<string | null>(null)

const accept = computed(() => EXTENSIONS[type.value].map((e) => `.${e}`).join(','))

// Its own instance: the global upload store would list the file as a new video and refresh the
// video list when it finished.
const uppy = markRaw(createS3Uppy())

uppy.on('upload-progress', (_, p) => {
  if (p.bytesTotal) progress.value = Math.round((p.bytesUploaded / p.bytesTotal) * 100)
})

uppy.on('upload-success', () => {
  uploading.value = false
  open.value = false
  toast.success('Track uploaded. It will appear in the player once it is processed.')
  emit('added')
})

uppy.on('upload-error', (_, e) => {
  uploading.value = false
  // The API's 422 explains what to fix (name taken, wrong extension…); Uppy surfaces its body.
  error.value = e.message
})

onUnmounted(() => uppy.destroy())

const show = () => {
  uppy.cancelAll()
  file.value = null
  type.value = 'subtitle'
  form.value = {
    name: '',
    language: '',
    forced: false,
    hearingImpaired: false,
    visualImpaired: false,
  }
  progress.value = 0
  error.value = null
  open.value = true
}

/** Picks the type from the extension, so the common case needs no extra click. */
const onFileChange = (event: Event) => {
  const picked = (event.target as HTMLInputElement).files?.[0] ?? null
  file.value = picked
  error.value = null

  const extension = picked?.name.split('.').pop()?.toLowerCase() ?? ''
  if (EXTENSIONS.subtitle.includes(extension)) type.value = 'subtitle'
  else if (EXTENSIONS.audio.includes(extension)) type.value = 'audio'

  if (picked && !form.value.name) form.value.name = picked.name.replace(/\.[^.]+$/, '')
}

const submit = () => {
  if (!file.value || uploading.value) return

  error.value = null
  uploading.value = true
  progress.value = 0
  uppy.cancelAll()
  refreshS3UppyCsrf(uppy)

  try {
    uppy.addFile({
      name: file.value.name,
      data: file.value,
      // `trackType`/`trackName`, not `type`/`name`: Uppy owns those meta keys for the file itself.
      meta: {
        project: projectsStore.currentProject?.ulid,
        video: videoUlid,
        trackType: type.value,
        trackName: form.value.name.trim(),
        language: form.value.language.trim(),
        forced: type.value === 'subtitle' && form.value.forced,
        hearingImpaired: form.value.hearingImpaired,
        visualImpaired: type.value === 'audio' && form.value.visualImpaired,
      },
    })
  } catch (e) {
    uploading.value = false
    error.value = e instanceof Error ? e.message : String(e)
    return
  }

  uppy.upload()
}

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Add track</DialogTitle>
        <DialogDescription>
          Adds an audio or subtitle track to the published video, encoded with its template's
          settings. The video keeps playing while the track is processed.
        </DialogDescription>
      </DialogHeader>
      <form class="grid gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
          <Label for="add_track_file">File</Label>
          <Input
            id="add_track_file"
            type="file"
            :accept="accept"
            required
            :disabled="uploading"
            @change="onFileChange"
          />
          <p class="text-xs text-muted-foreground">
            Audio is fitted to the video's length; a file more than 10% longer or shorter is
            refused.
          </p>
        </div>
        <div class="grid gap-2">
          <Label for="add_track_type">Type</Label>
          <Select v-model="type" :disabled="uploading">
            <SelectTrigger id="add_track_type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="subtitle">Subtitles</SelectItem>
              <SelectItem value="audio">Audio</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="grid gap-2">
          <Label for="add_track_name">Name</Label>
          <Input id="add_track_name" v-model="form.name" required maxlength="240" :disabled="uploading" />
          <p class="text-xs text-muted-foreground">Shown in the player's track selector.</p>
        </div>
        <div class="grid gap-2">
          <Label for="add_track_language">Language</Label>
          <Input
            id="add_track_language"
            v-model="form.language"
            required
            placeholder="es, en-US, es-MX"
            :disabled="uploading"
          />
        </div>
        <div v-if="type === 'subtitle'" class="flex items-center justify-between">
          <div>
            <Label for="add_track_forced">Forced</Label>
            <p class="text-xs text-muted-foreground">For foreign-language dialogue only.</p>
          </div>
          <Switch id="add_track_forced" v-model="form.forced" :disabled="uploading" />
        </div>
        <div v-if="type === 'audio'" class="flex items-center justify-between">
          <div>
            <Label for="add_track_ad">Audio description</Label>
            <p class="text-xs text-muted-foreground">
              Narrates the picture for blind and low-vision viewers.
            </p>
          </div>
          <Switch id="add_track_ad" v-model="form.visualImpaired" :disabled="uploading" />
        </div>
        <div class="flex items-center justify-between">
          <div>
            <Label for="add_track_sdh">{{
              type === 'subtitle' ? 'SDH' : 'Hearing impaired'
            }}</Label>
            <p class="text-xs text-muted-foreground">
              {{
                type === 'subtitle'
                  ? 'Subtitles for the deaf and hard of hearing.'
                  : 'A mix with clearer dialogue.'
              }}
            </p>
          </div>
          <Switch id="add_track_sdh" v-model="form.hearingImpaired" :disabled="uploading" />
        </div>
        <Progress v-if="uploading" :model-value="progress" class="h-1.5" aria-label="Upload progress" />
        <p v-if="error" role="alert" class="text-sm text-destructive">{{ error }}</p>
        <DialogFooter>
          <Button type="submit" :disabled="uploading || !file">
            {{ uploading ? `Uploading… ${progress}%` : 'Upload' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
