<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { Switch } from '@/components/ui/switch'
import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'
import CdnSettingsService from '@/services/CdnSettingsService'

const form = ref<App.Data.CdnSettingsData | null>(null)
const loading = ref(true)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

async function fetchSettings() {
  loading.value = true
  try {
    form.value = await CdnSettingsService.get()
  } catch (error) {
    if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (!form.value) return
  errors.value = {}
  saving.value = true
  try {
    const payload =
      form.value.provider === 'self_hosted'
        ? { provider: form.value.provider, selfHosted: form.value.selfHosted }
        : { provider: form.value.provider, bunny: form.value.bunny }
    form.value = await CdnSettingsService.update(payload)
    toast.success('CDN settings saved')
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    } else if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    saving.value = false
  }
}

onMounted(fetchSettings)
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">CDN Settings</h1>
      <p class="text-muted-foreground">Configure the active CDN provider and its signing keys.</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10">
      <Spinner />
    </div>

    <form v-else-if="form" @submit.prevent="handleSave" class="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle>Provider</CardTitle>
          <CardDescription>Which CDN serves playback URLs.</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="grid gap-2">
            <Label for="cdn_provider">Active provider</Label>
            <Select v-model="form.provider">
              <SelectTrigger id="cdn_provider">
                <SelectValue placeholder="Select provider" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="self_hosted">Self-hosted</SelectItem>
                <SelectItem value="bunny">Bunny</SelectItem>
              </SelectContent>
            </Select>
            <p v-if="errors.provider" class="text-sm text-destructive">{{ errors.provider[0] }}</p>
          </div>
        </CardContent>
      </Card>

      <Card v-if="form.provider === 'self_hosted'">
        <CardHeader>
          <CardTitle>Self-hosted</CardTitle>
          <CardDescription>Akamai token signing and edge nginx tuning for our own nodes.</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
          <div class="grid gap-2">
            <Label for="sh_token_secret">Token secret</Label>
            <Input id="sh_token_secret" v-model="form.selfHosted.tokenSecret" class="font-mono" />
            <p v-if="errors['selfHosted.tokenSecret']" class="text-sm text-destructive">{{ errors['selfHosted.tokenSecret'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_token_name">Token query name</Label>
            <Input id="sh_token_name" v-model="form.selfHosted.tokenName" />
            <p class="text-sm text-muted-foreground">
              Lowercase letters, digits and underscores. The edge reads this name from its nginx
              config, so a change only applies once the proxy nodes are redeployed, and links
              already signed with the previous name stop validating.
            </p>
            <p v-if="errors['selfHosted.tokenName']" class="text-sm text-destructive">{{ errors['selfHosted.tokenName'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_token_window">Token window (seconds)</Label>
            <Input id="sh_token_window" type="number" v-model.number="form.selfHosted.tokenWindow" />
            <p class="text-sm text-muted-foreground">
              How long a playback or download link stays valid when the request does not ask for
              its own lifetime with <code>expiresIn</code> (up to 7 days).
            </p>
            <p v-if="errors['selfHosted.tokenWindow']" class="text-sm text-destructive">{{ errors['selfHosted.tokenWindow'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <div class="flex items-center gap-3">
              <Switch id="sh_bind_ip" v-model="form.selfHosted.bindIp" />
              <Label for="sh_bind_ip">Bind playback links to the viewer's IP</Label>
            </div>
            <p class="text-sm text-muted-foreground">
              The edge refuses a playback link fetched from any address but the one it was minted
              for, so a shared link stops working. Integrations must mint with the viewer's
              <code>ip</code>, or every link is bound to their own server. A viewer whose address
              changes mid-session (Wi-Fi to mobile data, IPv4 to IPv6) has to request a new link.
              Downloads are not bound. Applies to links minted from then on; turning it off does
              not release links already bound until they expire.
            </p>
            <p v-if="errors['selfHosted.bindIp']" class="text-sm text-destructive">{{ errors['selfHosted.bindIp'][0] }}</p>
          </div>
          <p class="text-xs text-muted-foreground">
            The edge cache is not sized here: each proxy node caches into its own disk pool and
            sizes the cache to it when it starts.
          </p>
        </CardContent>
      </Card>

      <Card v-if="form.provider === 'bunny'">
        <CardHeader>
          <CardTitle>Bunny</CardTitle>
          <CardDescription>Token authentication for a Bunny pull zone.</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
          <div class="grid gap-2">
            <Label for="bunny_host">Pull zone host</Label>
            <Input id="bunny_host" v-model="form.bunny.host" placeholder="e.g. myzone.b-cdn.net" />
            <p v-if="errors['bunny.host']" class="text-sm text-destructive">{{ errors['bunny.host'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="bunny_token_key">Token key</Label>
            <Input id="bunny_token_key" v-model="form.bunny.tokenKey" class="font-mono" />
            <p v-if="errors['bunny.tokenKey']" class="text-sm text-destructive">{{ errors['bunny.tokenKey'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="bunny_token_window">Token window (seconds)</Label>
            <Input id="bunny_token_window" type="number" v-model.number="form.bunny.tokenWindow" />
            <p class="text-sm text-muted-foreground">
              How long a playback or download link stays valid when the request does not ask for
              its own lifetime with <code>expiresIn</code> (up to 7 days).
            </p>
            <p v-if="errors['bunny.tokenWindow']" class="text-sm text-destructive">{{ errors['bunny.tokenWindow'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="bunny_api_key">API key</Label>
            <Input id="bunny_api_key" v-model="form.bunny.apiKey" class="font-mono" />
            <p class="text-sm text-muted-foreground">Account API key, used to pull request logs into bandwidth analytics.</p>
            <p v-if="errors['bunny.apiKey']" class="text-sm text-destructive">{{ errors['bunny.apiKey'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="bunny_pull_zone_id">Pull zone ID</Label>
            <Input id="bunny_pull_zone_id" v-model="form.bunny.pullZoneId" />
            <p v-if="errors['bunny.pullZoneId']" class="text-sm text-destructive">{{ errors['bunny.pullZoneId'][0] }}</p>
          </div>
        </CardContent>
      </Card>

      <div class="flex justify-end">
        <Button type="submit" :disabled="saving">{{ saving ? 'Saving...' : 'Save Changes' }}</Button>
      </div>
    </form>
  </div>
</template>
