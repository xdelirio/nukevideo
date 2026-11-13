import apiClient from './api'

export type CdnSettingsUpdate = Omit<App.Data.CdnSettingsData, 'edgeS3'> & {
  // Write-only secret: empty keeps the stored one. An empty key clears the pair.
  edgeS3?: { accessKeyId: string; secretAccessKey?: string }
}

class CdnSettingsService {
  constructor(private api = apiClient) {}

  async get(): Promise<App.Data.CdnSettingsData> {
    const res = await this.api.get('/cdn-settings')
    return res.data.data
  }

  async update(payload: CdnSettingsUpdate): Promise<App.Data.CdnSettingsData> {
    const res = await this.api.patch('/cdn-settings', payload)
    return res.data.data
  }
}

export default new CdnSettingsService()
