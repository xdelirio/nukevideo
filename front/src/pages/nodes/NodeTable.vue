<script setup lang="ts">
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
import { ref, computed, onMounted, watch } from 'vue'
import { toast } from 'vue-sonner'
import NodeService from '@/services/NodeService'
import AnalyticsService from '@/services/AnalyticsService'
import { formatBytes } from '@/utils/byteFormatter'
import { ApiException } from '@/exceptions/ApiException'
type Node = App.Data.NodeData
import { EllipsisVertical, Pencil, Wrench, Trash2, Rocket, Play, Square, ScrollText } from '@lucide/vue'
import SetupDialog from './SetupDialog.vue'
import EditNodeDialog from './EditNodeDialog.vue'

const props = defineProps<{ nodes: Node[] }>()
const emit = defineEmits<{
  updated: [node: Node]
  deleted: [nodeId: number]
  operation: []
  logs: [nodeId?: number]
}>()

const setupDialog = ref<InstanceType<typeof SetupDialog> | null>(null)

// Delivery per proxy over the last week: served, fetched from S3, hit ratio. The number that
// says whether a node wants more disk (ratio falling with a full pool) or the fleet another
// node. Loaded once; the table polls node state, not analytics.
const edges = ref<Record<number, App.Data.Analytics.EdgeDeliveryData>>({})
const hasProxies = computed(() => props.nodes.some(n => n.type === 'proxy'))
const isoDay = (d: Date) => d.toISOString().slice(0, 10)

onMounted(async () => {
  const to = new Date()
  const from = new Date(to.getTime() - 6 * 86400000)
  try {
    for (const edge of await AnalyticsService.edges(isoDay(from), isoDay(to))) {
      edges.value[edge.nodeId] = edge
    }
  } catch {
    // The column simply stays empty; a failed analytics read is not a nodes-page problem.
  }
})

const hitRatioClass = (ratio: number | null) => {
  if (ratio === null) return 'text-muted-foreground'
  if (ratio >= 0.85) return 'text-emerald-500'
  if (ratio >= 0.6) return 'text-yellow-500'
  return 'text-red-500'
}
const editDialog = ref<InstanceType<typeof EditNodeDialog> | null>(null)
// Dialog open state is kept separate from the target node so that AlertDialogAction
// auto-closing the dialog never nulls the node mid-request (that race broke deletion).
const nodeToDelete = ref<Node | null>(null)
const deleteOpen = ref(false)

const askDelete = (node: Node) => {
  nodeToDelete.value = node
  deleteOpen.value = true
}

const confirmDelete = async () => {
  const node = nodeToDelete.value
  if (!node) return
  try {
    await NodeService.deleteNode(node.id)
    emit('deleted', node.id)
    toast.success(`Node "${node.name}" deleted`)
  } catch (error) {
    toast.error(error instanceof ApiException ? error.message : 'Failed to delete node')
  } finally {
    deleteOpen.value = false
    nodeToDelete.value = null
  }
}

const act = async (node: Node, action: App.Enums.NodeAction, force = false) => {
  // A proxy's deploy needs the disk list, which only the setup dialog shows.
  if (action === 'deploy' && node.type === 'proxy') return setupDialog.value?.show(node)
  // Force kills the encodes in flight; they only come back about 31 minutes later.
  if (force && !confirm(`${action === 'deploy' ? 'Deploy' : 'Stop'} "${node.name}" without waiting for its running jobs? They are killed and retried ~31 minutes later.`)) return
  try {
    if (action === 'deploy') await NodeService.deploy(node.id, { force })
    else if (action === 'start') await NodeService.start(node.id)
    else await NodeService.stop(node.id, force)
    toast.success(`${action[0]!.toUpperCase()}${action.slice(1)} queued for "${node.name}"`, {
      action: { label: 'View logs', onClick: () => emit('logs', node.id) },
    })
    emit('operation')
  } catch (error) {
    toast.error(error instanceof ApiException ? error.message : `Failed to ${action} "${node.name}"`)
  }
}

// Fleet deploy. Proxies go one at a time and never format a disk; workers all at once.
const selected = ref<number[]>([])
const forceBulk = ref(false)
const allSelected = computed(() => props.nodes.length > 0 && selected.value.length === props.nodes.length)

// A deleted node's id would stay selected with no row to untick it, and the deploy would 422.
watch(
  () => props.nodes,
  (nodes) => (selected.value = selected.value.filter((id) => nodes.some((n) => n.id === id))),
)

const toggle = (id: number, on: boolean) => {
  selected.value = on ? [...selected.value, id] : selected.value.filter(n => n !== id)
}

const deploySelected = async () => {
  if (forceBulk.value && !confirm(`Deploy ${selected.value.length} nodes without waiting for their running jobs? They are killed and retried ~31 minutes later.`)) return
  try {
    const { data, skipped } = await NodeService.deployMany({ nodes: selected.value, force: forceBulk.value })
    toast.success(`${data.length} ${data.length === 1 ? 'deploy' : 'deploys'} queued`
      + (skipped.length ? `, ${skipped.length} skipped (busy or stopped)` : ''), {
      action: { label: 'View logs', onClick: () => emit('logs') },
    })
    selected.value = []
    emit('operation')
  } catch (error) {
    toast.error(error instanceof ApiException ? error.message : 'Failed to queue the deploys')
  }
}

// One dot, four states. Draining and unhealthy only mean something on an active proxy: an
// inactive node is stopped, whatever the probe last saw.
const statusDot = (node: App.Data.NodeData) => {
  if (!node.isActive) return { class: 'bg-muted-foreground/40', title: 'Inactive' }
  if (node.isDraining) return { class: 'bg-yellow-500', title: 'Draining — no new playback links' }
  if (!node.isHealthy) return { class: 'bg-red-500', title: `Unhealthy — ${node.healthFailures} failed probes` }
  return { class: 'bg-emerald-500', title: 'Active' }
}
</script>

<template>
  <div v-if="selected.length" class="flex items-center gap-3 text-sm">
    <span>{{ selected.length }} selected</span>
    <label class="flex items-center gap-1.5 text-muted-foreground">
      <input v-model="forceBulk" type="checkbox" class="h-3.5 w-3.5" />
      Force (don't wait for running jobs)
    </label>
    <Button size="sm" @click="deploySelected">
      <Rocket class="mr-1 h-4 w-4" />
      Deploy selected
    </Button>
  </div>
  <div class="overflow-hidden rounded-lg border">
    <Table>
      <TableHeader class="bg-muted">
        <TableRow>
          <TableHead class="w-8">
            <input
              type="checkbox"
              class="h-3.5 w-3.5"
              :checked="allSelected"
              aria-label="Select all nodes"
              @change="selected = ($event.target as HTMLInputElement).checked ? props.nodes.map(n => n.id) : []"
            />
          </TableHead>
          <TableHead>Node</TableHead>
          <TableHead v-if="hasProxies" class="text-right">Delivery (7d)</TableHead>
          <TableHead class="text-right">Last Seen</TableHead>
          <TableHead class="w-12"></TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="node in props.nodes" :key="node.id">
          <TableCell>
            <input
              type="checkbox"
              class="h-3.5 w-3.5"
              :checked="selected.includes(node.id)"
              :aria-label="`Select ${node.name}`"
              @change="toggle(node.id, ($event.target as HTMLInputElement).checked)"
            />
          </TableCell>
          <TableCell>
            <div class="flex items-center gap-2">
              <span class="inline-block h-2 w-2 shrink-0 rounded-full"
                :class="statusDot(node).class"
                :title="statusDot(node).title" />
              <div class="flex flex-col">
                <span class="font-medium">
                  {{ node.name }}
                  <span v-if="node.isActive && node.isDraining" class="ml-1 text-xs font-normal text-yellow-500">draining</span>
                  <span v-else-if="node.isActive && !node.isHealthy" class="ml-1 text-xs font-normal text-red-500">unhealthy</span>
                </span>
                <span class="text-xs text-muted-foreground">{{ node.ipAddress }}</span>
                <span v-if="node.tailscaleIp" class="text-xs text-muted-foreground" title="Address on the Tailscale network">· tailscale {{ node.tailscaleIp }}</span>
              </div>
            </div>
          </TableCell>

          <TableCell v-if="hasProxies" class="text-right text-xs">
            <template v-if="node.type === 'proxy' && edges[node.id]">
              <span :class="hitRatioClass(edges[node.id].hitRatio)" title="Cache hit ratio, by bytes">
                {{ edges[node.id].hitRatio === null ? 'no cache traffic' : `${Math.round(edges[node.id].hitRatio! * 100)}% hit` }}
              </span>
              <div class="text-muted-foreground">
                {{ formatBytes(edges[node.id].deliveredBytes) }} served ·
                {{ formatBytes(edges[node.id].originBytes) }} from origin
              </div>
            </template>
            <span v-else-if="node.type === 'proxy'" class="text-muted-foreground">no traffic</span>
          </TableCell>

          <TableCell class="text-right text-xs text-muted-foreground">
            {{ node.lastSeenAt || '-' }}
          </TableCell>

          <TableCell>
            <DropdownMenu>
              <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="icon">
                  <EllipsisVertical class="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem @click="editDialog?.show(node)">
                  <Pencil class="mr-2 h-4 w-4" />
                  Edit
                </DropdownMenuItem>
                <DropdownMenuItem @click="setupDialog?.show(node)">
                  <Wrench class="mr-2 h-4 w-4" />
                  Setup
                </DropdownMenuItem>
                <DropdownMenuItem @click="emit('logs', node.id)">
                  <ScrollText class="mr-2 h-4 w-4" />
                  Logs
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem @click="act(node, 'deploy')">
                  <Rocket class="mr-2 h-4 w-4" />
                  Deploy
                </DropdownMenuItem>
                <DropdownMenuItem v-if="node.type === 'worker'" @click="act(node, 'deploy', true)">
                  <Rocket class="mr-2 h-4 w-4" />
                  Deploy (force)
                </DropdownMenuItem>
                <DropdownMenuItem v-if="!node.isActive" @click="act(node, 'start')">
                  <Play class="mr-2 h-4 w-4" />
                  Start
                </DropdownMenuItem>
                <template v-else>
                  <DropdownMenuItem @click="act(node, 'stop')">
                    <Square class="mr-2 h-4 w-4" />
                    Stop
                  </DropdownMenuItem>
                  <DropdownMenuItem @click="act(node, 'stop', true)">
                    <Square class="mr-2 h-4 w-4" />
                    Stop (force)
                  </DropdownMenuItem>
                </template>
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-destructive" @click="askDelete(node)">
                  <Trash2 class="mr-2 h-4 w-4" />
                  Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
    <SetupDialog ref="setupDialog" @node-updated="(node) => emit('updated', node)" @deployed="(id) => { emit('operation'); emit('logs', id) }" />
    <EditNodeDialog ref="editDialog" @updated="(node) => emit('updated', node)" />
  </div>

  <AlertDialog v-model:open="deleteOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Delete node</AlertDialogTitle>
        <AlertDialogDescription>
          Are you sure you want to delete node "{{ nodeToDelete?.name }}"? This action cannot be undone.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction @click="confirmDelete">Delete</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
