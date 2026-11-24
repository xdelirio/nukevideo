<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Pagination,
  PaginationContent,
  PaginationItem,
  PaginationNext,
  PaginationPrevious,
} from '@/components/ui/pagination'
import ActivityLogService from '@/services/ActivityLogService'
type ActivityLog = App.Data.ActivityLogData
import type { Pagination as ResPagination } from '@/types/Pagination'
import {
  AlertCircle,
  CheckCircle,
  PlayCircle,
  Download,
  Upload,
  Image,
  Subtitles,
  Server,
} from '@lucide/vue'

const logs = ref<ResPagination<ActivityLog>>({ currentPage: 1, data: [], perPage: 0, total: 0 })
const loading = ref(true)
const currentPage = ref(1)

// Kept in the URL, so a link can open the log already narrowed to one video's failure.
const route = useRoute()
const router = useRouter()
const ALL_EVENTS = 'all'
const eventFilter = ref(typeof route.query.event === 'string' ? route.query.event : ALL_EVENTS)
const videoFilter = ref(typeof route.query.video === 'string' ? route.query.video : '')

const filters = () => ({
  ...(eventFilter.value !== ALL_EVENTS ? { event: eventFilter.value } : {}),
  ...(videoFilter.value.trim() ? { video: videoFilter.value.trim() } : {}),
})

const applyFilters = () => {
  router.replace({ query: filters() })
  fetchLogs(1)
}

const fetchLogs = async (page = 1) => {
  try {
    loading.value = true
    currentPage.value = page
    logs.value = await ActivityLogService.index(page, filters())
  } catch (error) {
    console.error('Error fetching activity logs:', error)
  } finally {
    loading.value = false
  }
}

const eventConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline'; icon: typeof AlertCircle }> = {
  video_processing_started: { label: 'Processing Started', variant: 'secondary', icon: PlayCircle },
  video_completed: { label: 'Completed', variant: 'default', icon: CheckCircle },
  video_failed: { label: 'Failed', variant: 'destructive', icon: AlertCircle },
  stream_processing_failed: { label: 'Stream Failed', variant: 'destructive', icon: AlertCircle },
  stream_upload_failed: { label: 'Upload Failed', variant: 'destructive', icon: Upload },
  download_failed: { label: 'Download Failed', variant: 'destructive', icon: Download },
  thumbnail_failed: { label: 'Thumbnail Failed', variant: 'destructive', icon: Image },
  subtitles_failed: { label: 'Subtitles Failed', variant: 'destructive', icon: Subtitles },
  storyboard_failed: { label: 'Storyboard Failed', variant: 'destructive', icon: Image },
  video_upload_processing_failed: { label: 'Upload Processing Failed', variant: 'destructive', icon: AlertCircle },
}

// Node operations (admins only): deploy, start and stop, badged by how they ended.
const nodeActions: Record<string, string> = { node_deploy: 'Node Deploy', node_start: 'Node Start', node_stop: 'Node Stop' }
const nodeVariant = (status: unknown): 'default' | 'secondary' | 'destructive' =>
  status === 'failed' ? 'destructive' : status === 'succeeded' ? 'default' : 'secondary'

const getEventConfig = (log: ActivityLog) => {
  const node = nodeActions[log.event ?? '']
  if (node) {
    return { label: `${node} · ${log.properties?.status}`, variant: nodeVariant(log.properties?.status), icon: Server }
  }
  return eventConfig[log.event ?? ''] ?? { label: log.event ?? 'Unknown', variant: 'outline' as const, icon: AlertCircle }
}

const formatDate = (dateString: string): string => {
  return new Date(dateString).toLocaleString()
}

onMounted(() => {
  fetchLogs()
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-4xl">
    <div>
      <h1 class="text-2xl font-bold">Activity Log</h1>
      <p class="text-muted-foreground">Recent activity and events for your videos.</p>
    </div>

    <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
      <Select v-model="eventFilter" @update:model-value="applyFilters">
        <SelectTrigger class="w-56">
          <SelectValue placeholder="Every event" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem :value="ALL_EVENTS">Every event</SelectItem>
          <SelectItem v-for="(config, event) in eventConfig" :key="event" :value="event">{{ config.label }}</SelectItem>
        </SelectContent>
      </Select>
      <Input v-model="videoFilter" placeholder="Video ULID" class="w-72 font-mono" @change="applyFilters" />
    </form>

    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted">
          <TableRow>
            <TableHead>Event</TableHead>
            <TableHead>Description</TableHead>
            <TableHead>Date</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="3" class="text-center">
              <Spinner />
              Loading activity...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="logs.data.length === 0">
            <TableCell colspan="3" class="text-center text-muted-foreground">
              {{ eventFilter !== ALL_EVENTS || videoFilter ? 'No activity matches these filters.' : 'No activity yet.' }}
            </TableCell>
          </TableRow>
          <TableRow v-else v-for="log in logs.data" :key="log.id">
            <TableCell>
              <div class="flex items-center gap-2">
                <component :is="getEventConfig(log).icon" :size="16" class="text-muted-foreground" />
                <Badge :variant="getEventConfig(log).variant">
                  {{ getEventConfig(log).label }}
                </Badge>
              </div>
            </TableCell>
            <TableCell>
              <div class="space-y-1">
                <p class="text-sm">{{ log.description }}</p>
                <!-- A failed video records `reason`, the rest record `error`: only `error` was shown,
                     so why a video failed was in the log and never on screen. -->
                <p
                  v-if="log.properties?.error ?? log.properties?.reason"
                  class="text-xs text-destructive font-mono whitespace-pre-wrap break-all max-w-xl"
                >{{ log.properties?.error ?? log.properties?.reason }}</p>
              </div>
            </TableCell>
            <TableCell class="text-sm text-muted-foreground whitespace-nowrap">
              {{ formatDate(log.createdAt) }}
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>

      <div v-if="logs.total > logs.perPage" class="py-4">
        <Pagination v-slot="{ page }" :items-per-page="logs.perPage" :total="logs.total" :default-page="currentPage">
          <PaginationContent v-slot="{ items }">
            <PaginationPrevious @click="fetchLogs(currentPage - 1)" />
            <template v-for="(item, index) in items" :key="index">
              <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page" @click="fetchLogs(item.value)">
                {{ item.value }}
              </PaginationItem>
            </template>
            <PaginationNext @click="fetchLogs(currentPage + 1)" />
          </PaginationContent>
        </Pagination>
      </div>
    </div>
  </div>
</template>
