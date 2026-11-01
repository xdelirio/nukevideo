import { ref, computed, markRaw } from 'vue'
import { defineStore } from 'pinia'
import Uppy, { type Meta } from '@uppy/core'
import { type AwsBody } from '@uppy/aws-s3'
import AwsS3Multipart from '@uppy/aws-s3'
import type { FileUpload } from '@/types/FileUpload'
import { useProjectsStore } from './projects'

function getXsrfToken(): Record<string, string> {
  const token = document.cookie
    .split('; ')
    .find(row => row.startsWith('XSRF-TOKEN='))
    ?.split('=')[1]
  return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}
}

/**
 * An Uppy instance wired to the API's S3 endpoints (`/s3/params`, `/s3/multipart`). Shared by the
 * video upload store and the add-track dialog, which needs its own instance so its uploads never
 * show up in — or trigger listeners of — the global video upload queue.
 */
export function createS3Uppy(options: { debug?: boolean } = {}) {
  return new Uppy<Meta, AwsBody>({
    debug: options.debug ?? false,
    autoProceed: false,
  }).use(AwsS3Multipart, {
    endpoint: import.meta.env.VITE_URL_API,
    headers: getXsrfToken(),
    cookiesRule: 'include',
    limit: 4,
    getChunkSize() {
      return 30 * 1024 * 1024
    },
  })
}

/** {@link useUploadStore}'s `refreshCsrfHeader`, for any instance made by {@link createS3Uppy}. */
export function refreshS3UppyCsrf(uppy: Uppy<Meta, AwsBody>) {
  uppy.getPlugin<AwsS3Multipart<Meta, AwsBody>>('AwsS3Multipart')?.setOptions({ headers: getXsrfToken() })
}

export const useUploadStore = defineStore('upload', () => {
  const files = ref<FileUpload[]>([])
  const selectedTemplate = ref<string>('')
  // Applies to the whole batch, like the template: it is where the videos land in the encode
  // queue, which is a per-upload decision rather than a per-file detail.
  const selectedPriority = ref<App.Enums.VideoPriority>('normal')

  // True from the moment "Upload" is pressed until Uppy reports the first byte (or the first
  // failure) of that batch. `isUploading` below keys off progress, so it stays false during the
  // seconds the multipart handshake takes — long enough for a second press to queue the same
  // files again, which is where the duplicated videos came from. This flag closes that window:
  // `startUpload` is a no-op while it is set, and the button renders disabled.
  const isStarting = ref(false)

  const uppy = markRaw(createS3Uppy({ debug: true }))

  // Raw bytes uploaded per file, updated on every progress event. The global
  // speed is derived from the sum of these on a fixed 1s cadence (see below),
  // so the displayed number stays calm regardless of how often Uppy fires.
  const fileBytes = new Map<string, number>()
  const uploadSpeed = ref(0)
  let speedTimer: ReturnType<typeof setInterval> | null = null
  let lastTotal = 0
  let lastTime = 0

  function totalUploadedBytes() {
    let sum = 0
    for (const b of fileBytes.values()) sum += b
    return sum
  }

  function startSpeedTracking() {
    if (speedTimer) return
    lastTotal = totalUploadedBytes()
    lastTime = Date.now()
    speedTimer = setInterval(() => {
      const now = Date.now()
      const total = totalUploadedBytes()
      const elapsed = (now - lastTime) / 1000
      if (elapsed > 0) {
        const instant = Math.max(0, (total - lastTotal) / elapsed)
        // Smooth out jitter with an exponential moving average.
        uploadSpeed.value = uploadSpeed.value ? uploadSpeed.value * 0.7 + instant * 0.3 : instant
      }
      lastTotal = total
      lastTime = now
      if (!isUploading.value) stopSpeedTracking()
    }, 1000)
  }

  function stopSpeedTracking() {
    if (speedTimer) {
      clearInterval(speedTimer)
      speedTimer = null
    }
    uploadSpeed.value = 0
  }

  uppy.on('upload-progress', (uppyFile, progress) => {
    isStarting.value = false
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file && progress.bytesTotal) {
      file.progress = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100)
      fileBytes.set(file.uppyFileId!, progress.bytesUploaded)
      startSpeedTracking()
    }
  })

  uppy.on('upload-success', (uppyFile) => {
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file) {
      file.status = 'success'
      file.progress = 100
    }
  })

  uppy.on('upload-error', (uppyFile, error) => {
    isStarting.value = false
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file) {
      file.status = 'error'
      file.logError = error.message
      if (file.uppyFileId) fileBytes.delete(file.uppyFileId)
    }
  })

  const hasFiles = computed(() => files.value.length > 0)

  // Terminal files are excluded deliberately: an errored file keeps its last progress value, so
  // testing progress alone left the floating widget pinned on every page — and the 1s speed timer
  // running — for the rest of the session, with no way to dismiss it.
  const isUploading = computed(() =>
    files.value.some(f => f.status !== 'error' && f.status !== 'success' && f.progress > 0 && f.progress < 100),
  )

  function addFiles(newFiles: FileUpload[]) {
    files.value.push(...newFiles)
  }

  function removeFile(index: number) {
    const file = files.value[index]
    if (file?.uppyFileId) {
      uppy.removeFile(file.uppyFileId)
    }
    files.value.splice(index, 1)
  }

  function clearFiles() {
    uppy.cancelAll()
    files.value = []
    fileBytes.clear()
    stopSpeedTracking()
  }

  function startUpload() {
    if (isStarting.value) return

    const projectsStore = useProjectsStore()

    refreshCsrfHeader()

    isStarting.value = true

    try {
      files.value.forEach(file => {
        if (!file.uppyFileId && file.status === 'pending') {
          const uppyFileId = uppy.addFile({
            name: file.title,
            data: file.file,
            meta: {
              template: selectedTemplate.value,
              project: projectsStore.currentProject?.ulid,
              externalUserId: file.externalUserId,
              externalResourceId: file.externalResourceId,
              priority: selectedPriority.value,
            }
          })
          file.uppyFileId = uppyFileId
        }
      })
    } catch (error) {
      // A restriction error from `addFile` means nothing was handed over: release the button
      // rather than leave it stuck on "Starting" with no upload behind it.
      isStarting.value = false
      throw error
    }

    // The promise settles when the whole batch is done; the flag is released much earlier, by
    // the first progress or error event. `complete` is only the fallback for a batch that
    // produced neither (nothing to upload).
    uppy.upload().finally(() => {
      isStarting.value = false
    })
  }

  /**
   * The header is read at upload time, never snapshotted. The store is constructed when the app
   * mounts — before any request has run, so on a first visit there is no XSRF cookie yet — and
   * logging in regenerates the session token anyway. A frozen header meant every multipart
   * upload (the >100 MB path, the only one CSRF applies to) failed with a token mismatch until
   * the user reloaded the whole page.
   */
  function refreshCsrfHeader() {
    refreshS3UppyCsrf(uppy)
  }

  /**
   * Retries a failed transfer without making the user find the file on disk again.
   *
   * It restarts from zero, not from where it stopped: on a non-abort error Uppy's aws-s3 plugin
   * aborts the multipart upload server-side and clears the file's `key`/`uploadId`, so there is
   * no part list left to resume against and the backend mints a new key.
   *
   * A file the user cancelled is gone from Uppy's own state even though the row still carries its
   * id, and `retryUpload` on an unknown id throws — hence the lookup before anything is mutated,
   * so a failed retry can never leave the row in a state with no buttons at all.
   */
  function retryUpload(fileIndex: number) {
    const file = files.value[fileIndex]

    if (!file || file.status !== 'error' || !file.uppyFileId) {
      return
    }

    if (!uppy.getFile(file.uppyFileId)) {
      return
    }

    refreshCsrfHeader()

    uppy.retryUpload(file.uppyFileId)

    file.status = 'pending'
    file.logError = undefined
  }

  function setTemplate(templateId: string) {
    selectedTemplate.value = templateId
  }

  function cancelUpload(fileIndex: number) {
    const file = files.value[fileIndex]
    if (file?.uppyFileId) {
      uppy.removeFile(file.uppyFileId)
      file.status = 'error'
      file.logError = 'Cancelled by user'
      file.progress = 0
      fileBytes.delete(file.uppyFileId)
      // Uppy no longer knows this file, so the id is a dangling reference: keeping it made the
      // row look retryable and left it queued-but-not-queued, with every action disabled.
      file.uppyFileId = undefined
    }
  }

  function pauseUpload(fileIndex: number) {
    const file = files.value[fileIndex]
    if (file?.uppyFileId) {
      uppy.pauseResume(file.uppyFileId)
      file.status = 'paused'
    }
  }

  function resumeUpload(fileIndex: number) {
    const file = files.value[fileIndex]
    if (file?.uppyFileId && file.status === 'paused') {
      uppy.pauseResume(file.uppyFileId)
      file.status = 'pending'
    }
  }

  return {
    // State
    files,
    selectedTemplate,
    selectedPriority,
    uppy,

    // Computed
    hasFiles,
    isUploading,
    isStarting,
    uploadSpeed,

    // Actions
    addFiles,
    removeFile,
    clearFiles,
    startUpload,
    retryUpload,
    setTemplate,
    cancelUpload,
    pauseUpload,
    resumeUpload,
  }
})
