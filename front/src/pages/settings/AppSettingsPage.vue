<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import AppSettingsService from '@/services/AppSettingsService'
import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'
import { Copy, Check, RefreshCw, KeyRound, Upload } from '@lucide/vue'
import { toast } from 'vue-sonner'

type Settings = App.Data.AppSettingsData
type Rotation = App.Data.AppSettings.SshKeyRotationData

const settings = ref<Settings | null>(null)
const loading = ref(true)
const busy = ref(false)
const copied = ref(false)
const errors = ref<Record<string, string[]>>({})

const importing = ref(false)
const privateKey = ref('')
const rotation = ref<Rotation | null>(null)

// The token is write-only: the panel is told whether one is stored, never what it is.
const cloudflareToken = ref('')
const cloudflareProxied = ref(false)
const cloudflareBusy = ref(false)
const cloudflareZones = ref<string[]>([])
const cloudflareZonesError = ref<string | null>(null)

// Write-only like the token above: the panel learns whether a key is stored, never the key.
const tailscaleKey = ref('')
const tailscaleBusy = ref(false)

async function fetchCloudflareZones() {
  cloudflareZones.value = []
  cloudflareZonesError.value = null
  if (!settings.value?.cloudflareConfigured) return
  try {
    cloudflareZones.value = await AppSettingsService.cloudflareZones()
  } catch (error) {
    cloudflareZonesError.value = error instanceof ApiException ? error.message : 'Could not reach Cloudflare.'
  }
}

async function saveCloudflare() {
  cloudflareBusy.value = true
  errors.value = {}
  try {
    settings.value = await AppSettingsService.setCloudflare({
      ...(cloudflareToken.value ? { apiToken: cloudflareToken.value } : {}),
      proxied: cloudflareProxied.value,
    })
    cloudflareToken.value = ''
    toast.success('Cloudflare settings saved')
    await fetchCloudflareZones()
  } catch (error) {
    if (error instanceof ValidationException) errors.value = error.errors
    else if (error instanceof ApiException) toast.error(error.message)
  } finally {
    cloudflareBusy.value = false
  }
}

async function removeCloudflare() {
  cloudflareBusy.value = true
  errors.value = {}
  try {
    settings.value = await AppSettingsService.removeCloudflare()
    cloudflareProxied.value = false
    cloudflareToken.value = ''
    await fetchCloudflareZones()
  } finally {
    cloudflareBusy.value = false
  }
}

async function saveTailscale() {
  tailscaleBusy.value = true
  errors.value = {}
  try {
    settings.value = await AppSettingsService.setTailscale({ authKey: tailscaleKey.value })
    tailscaleKey.value = ''
    toast.success('Tailscale key saved')
  } catch (error) {
    if (error instanceof ValidationException) errors.value = error.errors
    else if (error instanceof ApiException) toast.error(error.message)
  } finally {
    tailscaleBusy.value = false
  }
}

async function removeTailscale() {
  tailscaleBusy.value = true
  errors.value = {}
  try {
    settings.value = await AppSettingsService.removeTailscale()
    tailscaleKey.value = ''
  } finally {
    tailscaleBusy.value = false
  }
}

async function fetchSettings() {
  try {
    loading.value = true
    settings.value = await AppSettingsService.get()
    cloudflareProxied.value = settings.value.cloudflareProxied
    // Not awaited: the page does not wait on a third party to show the SSH key.
    fetchCloudflareZones()
  } finally {
    loading.value = false
  }
}

async function generate() {
  busy.value = true
  errors.value = {}
  rotation.value = null
  try {
    settings.value = await AppSettingsService.setSshKey({ privateKey: null })
  } finally {
    busy.value = false
  }
}

async function importKey() {
  busy.value = true
  errors.value = {}
  rotation.value = null
  try {
    settings.value = await AppSettingsService.setSshKey({ privateKey: privateKey.value })
    privateKey.value = ''
    importing.value = false
  } catch (error) {
    if (error instanceof ValidationException) errors.value = error.errors
  } finally {
    busy.value = false
  }
}

async function rotate() {
  busy.value = true
  rotation.value = null
  try {
    rotation.value = await AppSettingsService.rotateSshKey()
    settings.value = await AppSettingsService.get()
  } finally {
    busy.value = false
  }
}

async function copyPublicKey() {
  if (!settings.value?.sshPublicKey) return
  await navigator.clipboard.writeText(settings.value.sshPublicKey)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

onMounted(fetchSettings)
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">Settings</h1>
      <p class="text-muted-foreground">Manage application configuration.</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <section v-else class="flex flex-col gap-4 rounded-lg border p-4">
      <div>
        <h2 class="text-lg font-semibold">SSH key</h2>
        <p class="text-sm text-muted-foreground">
          The key the panel connects to every node with. Add its public half to
          <code>~/.ssh/authorized_keys</code> of the SSH user on each node. The private half stays here,
          encrypted, and is never shown.
        </p>
      </div>

      <template v-if="settings?.sshPublicKey">
        <div class="grid gap-2">
          <Label>Public key</Label>
          <textarea
            readonly
            rows="3"
            :value="settings.sshPublicKey"
            class="flex w-full rounded-md border border-input bg-muted px-3 py-2 text-xs font-mono"
            @focus="($event.target as HTMLTextAreaElement).select()"
          />
          <p class="text-xs text-muted-foreground">Fingerprint <code>{{ settings.sshFingerprint }}</code></p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" @click="copyPublicKey">
            <Check v-if="copied" class="h-4 w-4 mr-2" />
            <Copy v-else class="h-4 w-4 mr-2" />
            {{ copied ? 'Copied' : 'Copy public key' }}
          </Button>
          <AlertDialog>
            <AlertDialogTrigger as-child>
              <Button type="button" variant="outline" size="sm" :disabled="busy">
                <RefreshCw class="h-4 w-4 mr-2" :class="{ 'animate-spin': busy }" />
                Rotate on all nodes
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Rotate the SSH key?</AlertDialogTitle>
                <AlertDialogDescription>
                  A new key is generated and installed on every node using the current one; the panel
                  switches only once every active node accepts it, then removes the old public key from
                  the nodes. If any active node cannot be reached nothing changes and it is listed below.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction @click="rotate">Rotate</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
          <Button type="button" variant="ghost" size="sm" :disabled="busy" @click="importing = !importing">
            <Upload class="h-4 w-4 mr-2" />
            Replace by importing
          </Button>
        </div>

        <div v-if="rotation" class="rounded-md border p-3 text-sm" :class="rotation.rotated ? 'border-green-500/40' : 'border-destructive/40'">
          <p class="font-medium">
            {{ rotation.rotated ? 'Key rotated. Every node now authenticates with the new key.' : 'Not rotated: an active node did not accept the new key. The current key stays in place.' }}
          </p>
          <ul v-if="rotation.nodes.length" class="mt-2 grid gap-1">
            <li v-for="node in rotation.nodes" :key="node.id" class="flex gap-2">
              <span :class="node.ok ? 'text-green-600' : 'text-destructive'">{{ node.ok ? '✓' : '✗' }}</span>
              <span>{{ node.name }}</span>
              <span v-if="node.error" class="text-muted-foreground truncate">— {{ node.error }}</span>
            </li>
          </ul>
          <p v-else class="mt-2 text-muted-foreground">No nodes yet, so there was nothing to install on.</p>
        </div>
      </template>

      <template v-else>
        <p class="text-sm">No key yet. Generate one here, or import the private half of a pair you already have.</p>
        <div class="flex gap-2">
          <Button type="button" size="sm" :disabled="busy" @click="generate">
            <KeyRound class="h-4 w-4 mr-2" />
            {{ busy ? 'Generating...' : 'Generate key' }}
          </Button>
          <Button type="button" variant="outline" size="sm" :disabled="busy" @click="importing = !importing">
            <Upload class="h-4 w-4 mr-2" />
            Import
          </Button>
        </div>
      </template>

      <form v-if="importing" class="grid gap-2 border-t pt-4" @submit.prevent="importKey">
        <Label for="ssh_private_key">Private key</Label>
        <textarea
          id="ssh_private_key"
          v-model="privateKey"
          placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
          required
          rows="5"
          class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
        <p class="text-xs text-muted-foreground">
          The public key is derived from it. This replaces the current key without touching the nodes:
          use it for a first key, or when the new public key is already on them. To swap keys on a
          running fleet, rotate instead.
        </p>
        <p v-if="errors.privateKey" class="text-sm text-destructive">{{ errors.privateKey[0] }}</p>
        <div class="flex gap-2">
          <Button type="submit" size="sm" :disabled="busy">{{ busy ? 'Importing...' : 'Import key' }}</Button>
          <Button type="button" variant="ghost" size="sm" @click="importing = false">Cancel</Button>
        </div>
      </form>
    </section>

    <section v-if="!loading" class="flex flex-col gap-4 rounded-lg border p-4">
      <div>
        <h2 class="text-lg font-semibold">Cloudflare DNS</h2>
        <p class="text-sm text-muted-foreground">
          Optional. With a token, deploying a proxy node points its hostname at the node in Cloudflare, and
          deleting the node removes the record. Only records created here are ever changed. The token needs
          <code>Zone → Zone → Read</code> and <code>Zone → DNS → Edit</code> on the zones your proxies use.
        </p>
      </div>

      <form class="grid gap-4" @submit.prevent="saveCloudflare">
        <div class="grid gap-2">
          <Label for="cloudflare_token">API token</Label>
          <Input
            id="cloudflare_token"
            v-model="cloudflareToken"
            type="password"
            autocomplete="off"
            class="font-mono"
            :required="!settings?.cloudflareConfigured"
            :placeholder="settings?.cloudflareConfigured ? 'Stored. Leave empty to keep it' : 'Cloudflare API token'"
          />
          <p v-if="errors.apiToken" class="text-sm text-destructive">{{ errors.apiToken[0] }}</p>
        </div>

        <div class="flex items-start justify-between gap-4">
          <div class="grid gap-1">
            <Label for="cloudflare_proxied">Proxy through Cloudflare</Label>
            <p class="text-xs text-muted-foreground">
              Off, records are DNS only and viewers reach the node directly. On, traffic goes through
              Cloudflare: check that its terms allow your video traffic, and that the zone's SSL mode lets
              the node's certificate be issued over HTTP. This is the default: a node can choose for itself
              in its own settings. Applies to each proxy at its next deploy.
            </p>
          </div>
          <Switch id="cloudflare_proxied" v-model="cloudflareProxied" />
        </div>

        <div v-if="settings?.cloudflareConfigured" class="text-sm">
          <p v-if="cloudflareZonesError" class="text-destructive">{{ cloudflareZonesError }}</p>
          <p v-else-if="cloudflareZones.length" class="text-muted-foreground">
            Zones this token covers: <span class="text-foreground">{{ cloudflareZones.join(', ') }}</span>
          </p>
          <p v-else class="text-muted-foreground">This token sees no zones yet, so no hostname will be covered.</p>
        </div>

        <div class="flex gap-2">
          <Button type="submit" size="sm" :disabled="cloudflareBusy">{{ cloudflareBusy ? 'Saving...' : 'Save' }}</Button>
          <AlertDialog v-if="settings?.cloudflareConfigured">
            <AlertDialogTrigger as-child>
              <Button type="button" variant="ghost" size="sm" :disabled="cloudflareBusy">Remove token</Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Remove the Cloudflare token?</AlertDialogTitle>
                <AlertDialogDescription>
                  The DNS records already created stay as they are, so the fleet keeps serving. From then on
                  they are yours to maintain: deploys stop updating them and deleting a node no longer
                  removes its record.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction @click="removeCloudflare">Remove</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </form>
    </section>

    <section v-if="!loading" class="flex flex-col gap-4 rounded-lg border p-4">
      <div>
        <h2 class="text-lg font-semibold">Tailscale</h2>
        <p class="text-sm text-muted-foreground">
          Optional. With an auth key, every worker joins the Tailscale network at its deploy, so the chunk
          store can sit on another provider and still be reached privately. Create a reusable key in the
          Tailscale admin console. Proxies are not joined. Workers already on the network stay there when
          the key is removed.
        </p>
      </div>

      <form class="grid gap-4" @submit.prevent="saveTailscale">
        <div class="grid gap-2">
          <Label for="tailscale_key">Auth key</Label>
          <Input
            id="tailscale_key"
            v-model="tailscaleKey"
            type="password"
            autocomplete="off"
            class="font-mono"
            :required="!settings?.tailscaleConfigured"
            :placeholder="settings?.tailscaleConfigured ? 'Stored. Leave empty to keep it' : 'tskey-auth-…'"
          />
          <p v-if="errors.authKey" class="text-sm text-destructive">{{ errors.authKey[0] }}</p>
        </div>

        <div class="flex gap-2">
          <Button type="submit" size="sm" :disabled="tailscaleBusy || !tailscaleKey">{{ tailscaleBusy ? 'Saving...' : 'Save' }}</Button>
          <AlertDialog v-if="settings?.tailscaleConfigured">
            <AlertDialogTrigger as-child>
              <Button type="button" variant="ghost" size="sm" :disabled="tailscaleBusy">Remove key</Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Remove the Tailscale key?</AlertDialogTitle>
                <AlertDialogDescription>
                  Workers already on the network stay on it. The next deploy of a worker will not join it.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction @click="removeTailscale">Remove</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </form>
    </section>
  </div>
</template>
