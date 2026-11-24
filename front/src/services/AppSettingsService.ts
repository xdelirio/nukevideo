import apiClient from './api'

class AppSettingsService {
  private readonly BASE_PATH = '/app-settings'

  constructor(private api = apiClient) {}

  async get(): Promise<App.Data.AppSettingsData> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  /** Replace the SSH key: with the private key given, or a freshly generated pair when omitted. */
  async setSshKey(payload: App.Data.AppSettings.UpdateSshKeyData): Promise<App.Data.AppSettingsData> {
    const res = await this.api.put(`${this.BASE_PATH}/ssh-key`, payload)
    return res.data.data
  }

  /** Install a new key on every node with the current one, verify it, then switch. */
  async rotateSshKey(): Promise<App.Data.AppSettings.SshKeyRotationData> {
    const res = await this.api.post(`${this.BASE_PATH}/ssh-key/rotate`)
    return res.data.data
  }

  /** Store a Cloudflare API token (omit it to keep the current one) and the record mode. */
  async setCloudflare(payload: Partial<App.Data.AppSettings.UpdateCloudflareData>): Promise<App.Data.AppSettingsData> {
    const res = await this.api.put(`${this.BASE_PATH}/cloudflare`, payload)
    return res.data.data
  }

  /** Forget the token. The DNS records it created are left in place. */
  async removeCloudflare(): Promise<App.Data.AppSettingsData> {
    const res = await this.api.delete(`${this.BASE_PATH}/cloudflare`)
    return res.data.data
  }

  /** Store the Tailscale auth key the workers join the tailnet with at their next deploy. */
  async setTailscale(payload: App.Data.AppSettings.UpdateTailscaleData): Promise<App.Data.AppSettingsData> {
    const res = await this.api.put(`${this.BASE_PATH}/tailscale`, payload)
    return res.data.data
  }

  /** Forget the key. Workers already on the tailnet stay there; the next deploy just does not join. */
  async removeTailscale(): Promise<App.Data.AppSettingsData> {
    const res = await this.api.delete(`${this.BASE_PATH}/tailscale`)
    return res.data.data
  }

  /** The zones the stored token can see, asked of Cloudflare now. */
  async cloudflareZones(): Promise<string[]> {
    const res = await this.api.get(`${this.BASE_PATH}/cloudflare/zones`)
    return res.data.data
  }
}

export default new AppSettingsService()
