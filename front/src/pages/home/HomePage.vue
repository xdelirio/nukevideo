<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { formatBytes } from '@/utils/byteFormatter'
import { formatSecondsToDetailedTime } from '@/utils/timeFormatter'
import { VisXYContainer, VisArea, VisAxis, VisLine } from '@unovis/vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { Input } from '@/components/ui/input'
import type { ChartConfig } from '@/components/ui/chart'
import { ChartContainer, ChartCrosshair, ChartTooltipContent, componentToString } from '@/components/ui/chart'
import { Badge } from '@/components/ui/badge'
import { useAnalytics } from '@/composables/useAnalytics'
import AnalyticsService from '@/services/AnalyticsService'
type AnalyticsCard = App.Data.Analytics.AnalyticsCardData
type BandwidthOverTime = App.Data.Analytics.BandwidthPointData

const { data, loading, from, to, fetchAnalytics } = useAnalytics()

const queue = ref<Record<string, number> | null>(null)

// The panel's own words for the API's card keys. The server sends `total_bandwidth`, not
// 'Total Bandwidth': display copy belongs to whoever is displaying it, and an API that shipped
// English would make this page untranslatable and every other consumer map it away. Same pattern
// as statusConfig below, which has always done it this way.
const cardLabels: Record<string, string> = {
  total_bandwidth: 'Total Bandwidth',
  unique_ips: 'Unique IPs',
  active_videos: 'Active Videos',
  tracking_ids: 'Tracking IDs',
  nodes: 'Nodes',
  cpu_encoding: 'CPU Encoding',
  upload_volume: 'Upload Volume',
}

const statusConfig: Record<string, { label: string; class: string }> = {
  pending: { label: 'Pending', class: 'border-yellow-500 text-yellow-500' },
  running: { label: 'Encoding', class: 'border-blue-500 text-blue-500' },
  failed: { label: 'Failed', class: 'border-red-500 text-red-500' },
}

const activeStatuses = computed(() => {
  if (!queue.value) return []
  return Object.entries(queue.value).filter(([_, count]) => count > 0)
})

const totalStreams = computed(() => {
  if (!queue.value) return 0
  return Object.values(queue.value).reduce((sum, count) => sum + count, 0)
})

onMounted(() => {
  fetchAnalytics()
  AnalyticsService.queueStatus().then(data => queue.value = data).catch(() => {})
})

const bwChartConfig = {
  bytes: { label: 'Bandwidth', color: 'var(--chart-1)' },
} satisfies ChartConfig

function formatDate(d: string) {
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function pctOfTotal(bytes: number): string {
  // Matched on the key, not on a display string. This used to look for 'Total Bandwidth' and every
  // percentage column silently read 0% if that wording ever changed.
  const total = data.cards.find(c => c.key === 'total_bandwidth')?.value ?? 0
  if (total === 0) return '0%'
  return `${((bytes / total) * 100).toFixed(1)}%`
}

function formatCardValue(card: AnalyticsCard): string {
  // The API says what the number is; how it reads is this page's business.
  switch (card.unit) {
    case 'bytes': return formatBytes(card.value)
    case 'seconds': return formatSecondsToDetailedTime(card.value)
    default: return card.value.toLocaleString()
  }
}
</script>

<template>
  <div class="p-6 space-y-6">
    <!-- Date Range -->
    <div class="flex items-center gap-3">
      <Input type="date" v-model="from" class="w-auto" />
      <span class="text-muted-foreground text-sm">to</span>
      <Input type="date" v-model="to" class="w-auto" />
    </div>

    <!-- Queue Status -->
    <Card v-if="queue">
      <CardContent class="flex items-center justify-between py-4">
        <div class="flex items-center gap-3">
          <span class="text-sm font-medium text-muted-foreground">Streams</span>
          <div v-if="activeStatuses.length" class="flex flex-wrap gap-2">
            <Badge
              v-for="[status, count] in activeStatuses"
              :key="status"
              variant="outline"
              :class="statusConfig[status]?.class"
            >
              {{ statusConfig[status]?.label }} {{ count }}
            </Badge>
          </div>
          <span v-else class="text-sm text-muted-foreground">Idle</span>
        </div>
        <span v-if="totalStreams > 0" class="text-2xl font-bold tracking-tight">{{ totalStreams }}</span>
      </CardContent>
    </Card>

    <!-- KPI Cards -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <template v-if="loading.cards">
        <Card v-for="i in 6" :key="i">
          <CardHeader class="pb-2">
            <Skeleton class="h-4 w-24" />
          </CardHeader>
          <CardContent>
            <Skeleton class="h-7 w-28" />
          </CardContent>
        </Card>
      </template>
      <Card v-else v-for="card in data.cards" :key="card.key">
        <CardHeader class="pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">{{ cardLabels[card.key] ?? card.key }}</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="text-2xl font-bold tracking-tight">{{ formatCardValue(card) }}</div>
        </CardContent>
      </Card>
    </div>

    <!-- Bandwidth Over Time -->
    <Card>
      <CardHeader>
        <CardTitle class="text-sm font-medium">Bandwidth Over Time</CardTitle>
      </CardHeader>
      <CardContent>
        <Skeleton v-if="loading.bandwidthOverTime" class="h-70 w-full" />
        <ChartContainer v-else-if="data.bandwidthOverTime.length" :config="bwChartConfig" class="h-70 w-full" :cursor="true">
          <VisXYContainer :data="data.bandwidthOverTime" :padding="{ top: 10 }">
            <VisArea
              :x="(_: BandwidthOverTime, i: number) => i"
              :y="(d: BandwidthOverTime) => d.bytes"
              color="var(--chart-1)"
              :opacity="0.1"
            />
            <VisLine
              :x="(_: BandwidthOverTime, i: number) => i"
              :y="(d: BandwidthOverTime) => d.bytes"
              color="var(--chart-1)"
              :line-width="2"
            />
            <VisAxis type="x" :x="(_: BandwidthOverTime, i: number) => i" :tick-format="(i: number) => data!.bandwidthOverTime[i] ? formatDate(data!.bandwidthOverTime[i].date) : ''" :num-ticks="6" />
            <VisAxis type="y" :tick-format="(v: number) => formatBytes(v)" />
            <ChartCrosshair color="var(--chart-1)" :template="componentToString(bwChartConfig, ChartTooltipContent, { labelFormatter: (i: number | Date) => data!.bandwidthOverTime[Number(i)] ? formatDate(data!.bandwidthOverTime[Number(i)]!.date) : '' })" />
          </VisXYContainer>
        </ChartContainer>
        <div v-else class="flex items-center justify-center h-70 text-sm text-muted-foreground">
          No data for this period
        </div>
      </CardContent>
    </Card>

    <!-- Tables -->
    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Top IPs -->
      <Card>
        <CardHeader>
          <CardTitle class="text-sm font-medium">Top IPs by Bandwidth</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading.topIps" class="h-64 w-full" />
          <Table v-else-if="data.topIps.length">
            <TableHeader>
              <TableRow>
                <TableHead>IP</TableHead>
                <TableHead class="text-right">Bandwidth</TableHead>
                <TableHead class="text-right">%</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="ip in data.topIps" :key="ip.ip">
                <TableCell class="font-mono text-xs">{{ ip.ip }}</TableCell>
                <TableCell class="text-right text-xs">{{ formatBytes(ip.bytes) }}</TableCell>
                <TableCell class="text-right text-xs text-muted-foreground">{{ pctOfTotal(ip.bytes) }}</TableCell>
              </TableRow>
            </TableBody>
          </Table>
          <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
            No data
          </div>
        </CardContent>
      </Card>

      <!-- Top Videos -->
      <Card>
        <CardHeader>
          <CardTitle class="text-sm font-medium">Top Videos by Bandwidth</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading.topVideos" class="h-64 w-full" />
          <Table v-else-if="data.topVideos.length">
            <TableHeader>
              <TableRow>
                <TableHead>Video</TableHead>
                <TableHead class="text-right">Bandwidth</TableHead>
                <TableHead class="text-right">%</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="(video, i) in data.topVideos" :key="video.video || i">
                <TableCell class="font-mono text-xs">
                  <RouterLink v-if="video.video" :to="{ name: 'Video', params: { id: video.video } }" class="hover:underline text-primary">
                    {{ (video.externalResourceId || video.video).slice(0, 8) }}
                  </RouterLink>
                  <span v-else class="text-muted-foreground">{{ video.externalResourceId?.slice(0, 8) || '—' }}</span>
                </TableCell>
                <TableCell class="text-right text-xs">{{ formatBytes(video.bytes) }}</TableCell>
                <TableCell class="text-right text-xs text-muted-foreground">{{ pctOfTotal(video.bytes) }}</TableCell>
              </TableRow>
            </TableBody>
          </Table>
          <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
            No data
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Top Tracking IDs -->
    <Card>
      <CardHeader>
        <CardTitle class="text-sm font-medium">Top Download Tracking IDs by Bandwidth</CardTitle>
      </CardHeader>
      <CardContent>
        <Skeleton v-if="loading.topTrackingIds" class="h-64 w-full" />
        <Table v-else-if="data.topTrackingIds.length">
          <TableHeader>
            <TableRow>
              <TableHead>Tracking ID</TableHead>
              <TableHead class="text-right">Videos</TableHead>
              <TableHead class="text-right">Unique IPs</TableHead>
              <TableHead class="text-right">Bandwidth</TableHead>
              <TableHead class="text-right">%</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <!-- The empty id is real traffic, not a gap: playback, and downloads minted without
                 one. Labelled rather than hidden, so the rows add up to the total above. -->
            <TableRow v-for="row in data.topTrackingIds" :key="row.trackingId || '__none__'">
              <TableCell class="font-mono text-xs">
                <span v-if="row.trackingId">{{ row.trackingId }}</span>
                <span v-else class="text-muted-foreground italic">Not attributed</span>
              </TableCell>
              <TableCell class="text-right text-xs">{{ row.videos }}</TableCell>
              <TableCell class="text-right text-xs">{{ row.uniqueIps }}</TableCell>
              <TableCell class="text-right text-xs">{{ formatBytes(row.bytes) }}</TableCell>
              <TableCell class="text-right text-xs text-muted-foreground">{{ pctOfTotal(row.bytes) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
          No data
        </div>
      </CardContent>
    </Card>

    <!-- Top External Users -->
    <Card>
      <CardHeader>
        <CardTitle class="text-sm font-medium">Top External Users by Upload Volume</CardTitle>
      </CardHeader>
      <CardContent>
        <Skeleton v-if="loading.topExternalUsers" class="h-64 w-full" />
        <Table v-else-if="data.topExternalUsers.length">
          <TableHeader>
            <TableRow>
              <TableHead>External User ID</TableHead>
              <TableHead class="text-right">Upload Volume</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="user in data.topExternalUsers" :key="user.externalUserId">
              <TableCell class="font-mono text-xs">{{ user.externalUserId }}</TableCell>
              <TableCell class="text-right text-xs">{{ formatBytes(user.bytes) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
          No data
        </div>
      </CardContent>
    </Card>
  </div>
</template>
