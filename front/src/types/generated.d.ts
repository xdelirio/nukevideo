declare namespace App {
namespace Data {
export type ActivityLogData = {
id: number,
logName: string | null,
description: string,
subjectType: string | null,
subjectId: number | null,
causerType: string | null,
causerId: number | null,
event: string | null,
properties: Record<string, any>,
createdAt: string,
updatedAt: string | null,
};
export type ApiTokenData = {
id: number,
name: string,
abilities: string[] | null,
lastUsedAt: string | null,
createdAt: string,
expiresAt: string | null,
token?: string,
};
export type AppSettingsData = {
sshPublicKey: string | null,
sshFingerprint: string | null,
cloudflareConfigured: boolean,
cloudflareProxied: boolean,
};
export type CacheDiskData = {
device: string,
size: number,
model: string,
state: string,
detail: string,
free: number,
fast: boolean,
};
export type CdnSettingsData = {
tokenSecret: string,
tokenName: string,
tokenWindow: number,
bindIp: boolean,
allowedDomains: string[],
allowNullOrigin: boolean,
edgeS3: App.Data.EdgeS3Data,
};
export type DownloadLinkData = {
url: string,
expiresAt: string,
filename: string,
type: string,
size: number | null,
};
export type EdgeS3Data = {
accessKeyId: string,
secretConfigured: boolean,
};
export type NodeData = {
id: number,
uuid: string,
name: string,
user: string | null,
ipAddress: string,
type: App.Enums.NodeType,
accel: App.Enums.NodeAccel | null,
hostname: string | null,
cloudflareProxied: boolean | null,
isActive: boolean,
isDraining: boolean,
isHealthy: boolean,
healthFailures: number,
lastHealthyAt: string | null,
services: App.Data.ServiceStatusData[],
log: string | null,
env: string | null,
lastSeenAt: string | null,
};
export type OutputData = {
ulid: string,
formats: string[],
status: App.Enums.VideoStatus,
progress: number,
streams: App.Data.StreamData[],
createdAt: string,
};
export type ProjectData = {
ulid: string,
name: string,
settings: App.Data.ProjectSettingsData | null,
apiKey: App.Data.ApiTokenData | null,
createdAt: string,
updatedAt: string | null,
};
export type ProjectSettingsData = {
webhookUrl: string | null,
webhookSecret: string | null,
};
export type ProjectStorageData = {
videos: number,
storage: App.Data.StorageData,
byStatus: App.Data.StatusStorageData[],
};
export type RequestData = object;
export type ServiceStatusData = {
name: string,
running: number,
desired: number | null,
state: string,
};
export type SkippedTrackData = {
ulid: string,
reason: App.Enums.DownloadSkipReason,
};
export type StatusStorageData = {
status: string,
videos: number,
storage: App.Data.StorageData,
};
export type StorageData = {
totalBytes: number,
servedBytes: number,
sourceBytes: number,
};
export type StreamData = {
ulid: string,
name: string | null,
type: string,
status: App.Enums.StreamStatus | null,
packageSize: number | null,
fileSize: number | null,
inputParams: Record<string, any> | null,
meta: Record<string, any> | null,
width: number | null,
height: number | null,
language: string | null,
forced: boolean,
hearingImpaired: boolean,
channels: number | null,
errorLog: string | null,
createdAt: string,
};
export type TemplateData = {
ulid: string,
name: string,
query: Record<string, any>,
enabled: boolean,
version: number,
keepProcessedFiles: boolean,
keepOriginal: boolean,
commands: string[],
createdAt: string,
updatedAt: string | null,
};
export type TemplatePresetData = {
slug: string,
name: string,
description: string,
category: string,
query: Record<string, any>,
};
export type UserData = {
id: number,
name: string,
email: string,
isAdmin: boolean,
projects: App.Data.ProjectData[],
};
export type ValidationCheckData = {
key: string,
label: string,
status: string,
output: string,
};
export type VideoData = {
ulid: string,
name: string,
duration: number,
aspectRatio: string,
status: App.Enums.VideoStatus,
priority: App.Enums.VideoPriority,
createdAt: string,
updatedAt: string,
externalUserId: string | null,
externalResourceId: string | null,
template: App.Data.VideoTemplateData | null,
thumbnailUrl: string,
storyboardUrl: string,
outputs: App.Data.OutputData[],
streams: App.Data.StreamData[],
size: number,
servedSize: number,
};
export type VideoDownloadLinksData = {
links: App.Data.DownloadLinkData[],
skipped: App.Data.SkippedTrackData[],
};
export type VideoTemplateData = {
ulid: string,
name: string,
version: number | null,
};
export type VideoVodLinksData = {
videoUlid: string,
thumbnailUrl: string,
storyboardUrl: string,
expiresAt: string,
sources: App.Data.VodSourceData[],
subtitles: App.Data.VodSubtitleData[],
};
export type VodSourceData = {
url: string,
format: string,
outputUlid: string,
videoCodec: string | null,
audioCodec: string | null,
};
export type VodSubtitleData = {
url: string,
streamUlid: string,
language: string | null,
name: string | null,
forced: boolean,
hearingImpaired: boolean,
};
namespace Analytics {
export type AnalyticsCardData = {
key: string,
value: number,
unit: App.Enums.MetricUnit,
};
export type AnalyticsData = {
cards: App.Data.Analytics.AnalyticsCardData[],
bandwidthOverTime: App.Data.Analytics.BandwidthPointData[],
topIps: App.Data.Analytics.TopIpData[],
topVideos: App.Data.Analytics.TopVideoData[],
topExternalUsers: App.Data.Analytics.TopExternalUserData[],
topTrackingIds: App.Data.Analytics.TopTrackingIdData[],
bandwidthByVideo: App.Data.Analytics.BandwidthByVideoData[],
encodingOverTime: App.Data.Analytics.EncodingPointData[],
};
export type BandwidthByVideoData = {
date: string,
video: string,
bytes: number,
};
export type BandwidthPointData = {
date: string,
bytes: number,
sessions: number,
};
export type BatchQueryData = object;
export type DashboardQueryData = {
from: string,
to: string,
video: string | null,
trackingId?: string | null,
metric: string | null,
limit: number | null,
videoSeriesLimit: number | null,
};
export type EdgeDeliveryData = {
nodeId: number,
deliveredBytes: number,
originBytes: number,
hitRatio: number | null,
};
export type EdgeDeliveryQueryData = {
from: string,
to: string,
};
export type EncodingPointData = {
date: string,
device: string,
seconds: number,
};
export type MetricsQueryData = {
from: string,
to: string,
dimensions: string[],
metrics: string[],
videos: string[],
trackingIds: string[],
externalUserIds: string[],
shape: App.Enums.MetricShape,
measures: string[],
};
export type SharedIpData = {
ip: string,
country: string,
trackingIdCount: number,
bytes: number,
days: number,
trackingIds: string[],
};
export type SharedIpsQueryData = {
from: string,
to: string,
minTrackingIds: number | null,
maxTrackingIds: number | null,
limit: number | null,
offset: number | null,
};
export type TopExternalUserData = {
externalUserId: string,
bytes: number,
};
export type TopIpData = {
ip: string,
bytes: number,
sessions: number,
};
export type TopTrackingIdData = {
trackingId: string,
bytes: number,
videos: number,
uniqueIps: number,
};
export type TopVideoData = {
video: string,
externalResourceId: string | null,
bytes: number,
sessions: number,
uniqueIps: number,
};
export type TrackingIdBytesData = {
trackingId: string,
metric: string,
bytes: number,
date: string | null,
};
export type TrackingIdBytesQueryData = {
from: string,
to: string,
trackingIds: string[],
metric: string | null,
granularity: App.Enums.UsageGranularity,
includeUnattributed: boolean,
};
export type TrackingIdIpData = {
ip: string,
country: string,
bytes: number,
requests: number,
days: number,
firstSeen: string,
lastSeen: string,
};
export type TrackingIdIpsQueryData = {
from: string,
to: string,
limit: number | null,
};
export type VideoBytesData = {
video: string,
metric: string,
bytes: number,
date: string | null,
};
export type VideoBytesQueryData = {
from: string,
to: string,
videos: string[],
metric: string | null,
granularity: App.Enums.UsageGranularity,
};
}
namespace ApiToken {
export type StoreApiTokenData = {
name: string,
};
}
namespace AppSettings {
export type NodeRotationData = {
id: number,
name: string,
ok: boolean,
error: string | null,
};
export type SshKeyRotationData = {
rotated: boolean,
nodes: App.Data.AppSettings.NodeRotationData[],
};
export type UpdateCloudflareData = {
apiToken: string | null,
proxied: boolean,
};
export type UpdateSshKeyData = {
privateKey: string | null,
};
}
namespace Auth {
export type LoginData = {
email: string,
password: string,
};
}
namespace ExternalUser {
export type DeleteExternalUserVideosData = {
confirm: string,
};
export type ExternalUserData = {
externalUserId: string,
videos: App.Data.ExternalUser.ExternalUserVideosData,
storage: App.Data.StorageData,
usage: App.Data.ExternalUser.ExternalUserUsageData,
};
export type ExternalUserListItemData = {
externalUserId: string,
videos: number,
storageBytes: number,
lastUploadAt: string,
};
export type ExternalUserMetricData = {
metric: string,
unit: App.Enums.MetricUnit,
value: number,
requests: number,
};
export type ExternalUserQueryData = {
from: string,
to: string,
};
export type ExternalUserUsageData = {
from: string,
to: string,
deliveredBytes: number,
uniqueIps: number,
uniqueTrackingIds: number,
metrics: App.Data.ExternalUser.ExternalUserMetricData[],
};
export type ExternalUserUsageQueryData = {
from: string,
to: string,
externalUserIds: string[],
metric: string | null,
granularity: App.Enums.UsageGranularity,
};
export type ExternalUserUsageRowData = {
externalUserId: string,
metric: string,
unit: App.Enums.MetricUnit,
value: number,
requests: number,
date: string | null,
};
export type ExternalUserVideosData = {
total: number,
byStatus: Record<string, number>,
};
export type IndexExternalUsersData = {
search: string | null,
sort: string,
direction: string,
perPage: number,
};
}
namespace Node {
export type DeployNodeData = {
disks?: Array<any> | null,
sharedDisks: Array<any>,
force: boolean,
};
export type DeployNodesData = {
nodes: number[],
force: boolean,
};
export type StopNodeData = {
force: boolean,
};
export type StoreNodeData = {
name: string,
ipAddress: string,
type: string,
accel: string | null,
user?: string,
hostname: string | null,
cloudflareProxied: boolean | null,
};
export type UpdateNodeData = {
name?: string,
user?: string | null,
ipAddress?: string,
hostname?: string | null,
cloudflareProxied?: boolean | null,
isDraining?: boolean,
accel?: string | null,
env?: string | null,
};
export type UpdateNodeEnvironmentData = {
environment: string | null,
chunkStoreAddress?: string | null,
};
}
namespace Profile {
export type UpdatePasswordData = {
currentPassword: string,
password: string,
};
export type UpdateProfileData = {
name: string,
email: string,
};
}
namespace Project {
export type StoreProjectData = {
name: string,
settings?: App.Data.ProjectSettingsData,
};
export type UpdateProjectData = {
name?: string,
settings?: App.Data.ProjectSettingsData,
};
}
namespace Stream {
export type DownloadStreamData = {
trackingId: string | null,
speedLimitKbps: number | null,
expiresIn: number | null,
};
export type UpdateStreamData = {
name: string,
language: string | null,
forced: boolean,
hearingImpaired: boolean | null,
};
}
namespace Template {
export type ReorderTemplatesData = {
ulids: string[],
};
export type StoreTemplateData = {
name: string,
enabled?: boolean,
keepProcessedFiles?: boolean,
keepOriginal?: boolean,
query: Record<string, any>,
};
export type UpdateTemplateData = {
name?: string,
enabled?: boolean,
keepProcessedFiles?: boolean,
keepOriginal?: boolean,
query: Record<string, any>,
};
}
namespace User {
export type StoreUserData = {
name: string,
email: string,
password: string,
isAdmin?: boolean,
};
export type UpdateUserData = {
name?: string,
email?: string,
password?: string,
isAdmin?: boolean,
};
}
namespace Video {
export type DownloadVideoTracksData = {
streamUlids: string[] | null,
trackingId: string | null,
speedLimitKbps: number | null,
expiresIn: number | null,
};
export type IndexVideosData = {
search: string | null,
externalUserId: string | null,
externalResourceId: string | null,
status: string | null,
updatedSince: string | null,
sort: string,
direction: string,
perPage: number,
};
export type ManifestOptions = {
resolution: number | null,
subtitlesFormat: App.Enums.SubtitleFormat,
};
export type PlayVideoData = {
resolution: number | null,
ip: string | null,
trackingId: string | null,
speedLimitKbps: number | null,
download: boolean | null,
expiresIn: number | null,
subtitlesFormat: App.Enums.SubtitleFormat | null,
};
export type PlayVideosData = {
videos: string[],
resolution: number | null,
ip: string | null,
trackingId: string | null,
speedLimitKbps: number | null,
download: boolean | null,
expiresIn: number | null,
subtitlesFormat: App.Enums.SubtitleFormat | null,
};
export type UpdateVideoData = {
name: string,
externalUserId?: string | null,
externalResourceId?: string | null,
priority?: App.Enums.VideoPriority,
};
}
}
namespace Enums {
export type DownloadSkipReason = 'not_retained' | 'not_downloadable' | 'not_found';
export type MetricDimension = 'date' | 'metric' | 'tracking_id' | 'video' | 'external_user_id' | 'ip' | 'country' | 'node_id' | 'cache';
export type MetricMeasure = 'value' | 'requests' | 'unique_ips' | 'unique_tracking_ids';
export type MetricShape = 'long' | 'wide';
export type MetricUnit = 'bytes' | 'seconds' | 'count';
export type NodeAccel = 'intel' | 'nvidia';
export type NodeAction = 'deploy' | 'start' | 'stop';
export type NodeType = 'worker' | 'proxy';
export type StreamStatus = 'processing' | 'ready' | 'failed';
export type SubtitleFormat = 'segments' | 'vtt';
export type UsageGranularity = 'total' | 'daily';
export type UsageMetric = 'upload_bytes' | 'encoding_cpu' | 'streaming_bytes' | 'download_bytes' | 'asset_bytes' | 'bandwidth_bytes' | 'origin_bytes';
export type VideoPriority = 'low' | 'normal' | 'high';
export type VideoStatus = 'pending' | 'failed' | 'running' | 'completed' | 'uploading' | 'downloading';
}
}
declare namespace Illuminate {
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Spatie {
namespace LaravelData {
export type CursorPaginatedDataCollection<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type PaginatedDataCollection<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
}
