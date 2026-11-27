<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Download } from '@lucide/vue'
import SettingsService from '@/services/SettingsService'

const RELEASES_URL = 'https://github.com/xdelirio/nukevideo/releases'

const version = ref<{ current: string; latest: string; behind: number } | null>(null)

onMounted(async () => {
  try {
    version.value = await SettingsService.versionCheck()
  } catch {
    // version is non-critical; stay silent
  }
})
</script>

<template>
  <template v-if="version">
    <a
      v-if="version.behind > 0"
      :href="RELEASES_URL"
      target="_blank"
      rel="noopener noreferrer"
      :title="`Latest: ${version.latest}`"
      class="relative flex h-10 items-center gap-2 rounded-lg border border-input bg-background px-4 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground group-data-[collapsible=icon]:hidden"
    >
      <Download class="h-4 w-4 flex-shrink-0" />
      <span class="truncate">Update Available</span>
      <span class="absolute right-2 flex h-2 w-2">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
      </span>
    </a>

    <div class="px-3 text-center text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
      Version v{{ version.current }}
    </div>
  </template>
</template>
