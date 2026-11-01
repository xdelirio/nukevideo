import apiClient from './api'

class AnalyticsService {
  private readonly BASE_PATH = '/analytics'

  constructor(private api = apiClient) {}

  // One request per panel, so the page can ask for all of them at once and draw each as it
  // lands instead of waiting on the slowest breakdown.

  summary(from: string, to: string) {
    return this.panel<App.Data.Analytics.AnalyticsCardData[]>('summary', from, to)
  }

  bandwidth(from: string, to: string) {
    return this.panel<App.Data.Analytics.BandwidthPointData[]>('bandwidth', from, to)
  }

  topIps(from: string, to: string) {
    return this.panel<App.Data.Analytics.TopIpData[]>('top-ips', from, to)
  }

  topVideos(from: string, to: string) {
    return this.panel<App.Data.Analytics.TopVideoData[]>('top-videos', from, to)
  }

  topTrackingIds(from: string, to: string) {
    return this.panel<App.Data.Analytics.TopTrackingIdData[]>('top-tracking-ids', from, to)
  }

  topExternalUsers(from: string, to: string) {
    return this.panel<App.Data.Analytics.TopExternalUserData[]>('top-external-users', from, to)
  }

  async edges(from: string, to: string): Promise<App.Data.Analytics.EdgeDeliveryData[]> {
    const res = await this.api.get(`${this.BASE_PATH}/edges`, { params: { from, to } })
    return res.data.data
  }

  async queueStatus(): Promise<Record<string, number>> {
    const res = await this.api.get(`${this.BASE_PATH}/queue`)
    return res.data.data
  }

  private async panel<T>(path: string, from: string, to: string): Promise<T> {
    const res = await this.api.get(`${this.BASE_PATH}/${path}`, { params: { from, to } })
    return res.data.data
  }
}

export default new AnalyticsService()
