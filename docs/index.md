---
layout: home

hero:
  name: NukeVideo
  text: Video Processing & Delivery Engine
  tagline: Open-source, self-hosted core for uploading, encoding, and serving video. Integrate it into your own backend.
  actions:
    - theme: brand
      text: Get Started
      link: /guide/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/chikenare/nukevideo

features:
  - icon: 🎬
    title: Distributed Encoding
    details: Videos are split into keyframe-aligned chunks and encoded in parallel across CPU (x264, x265, SVT-AV1) and GPU (Intel QSV, NVIDIA NVENC) worker nodes with FFmpeg. Audio is transcoded to AAC or Opus.
  - icon: 🎯
    title: Per-Title VMAF CRF
    details: The pipeline probes sample windows of each source, measures VMAF, and interpolates the CRF needed to hit a target quality per rendition.
  - icon: 📦
    title: Static CMAF Packaging
    details: shaka-packager builds each output once into shared CMAF segments that serve both HLS and DASH, with subtitles grafted into the same manifests. No on-the-fly repackaging.
  - icon: 📡
    title: Self-Hosted Delivery
    details: Serve through self-hosted proxy nodes with token-validated, S3-backed, edge-cached delivery.
  - icon: 🗄️
    title: S3-Compatible Storage
    details: Store originals and packaged output in any S3-compatible service — AWS S3, MinIO, RustFS, iDrive e2 — with s5cmd for fast transfers.
  - icon: 📊
    title: Analytics & API
    details: MariaDB for application data and for bandwidth and usage analytics, plus a full REST API with Sanctum auth and Uppy multipart uploads.
---
