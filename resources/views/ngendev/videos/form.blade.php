@extends('layouts.kaiadmin')
@php $isEdit = isset($video); @endphp

@section('title', $isEdit ? 'Edit Video' : 'Add New Video')

@section('content')
<div class="page-inner">

  <div class="page-header-bar">
    <div class="header-left">
      @if($isEdit)
        <h3><i class="fas fa-edit me-2 text-primary"></i> Edit Video</h3>
        <p>Update video record — Category: <strong>{{ $video->category->category_name ?? '—' }}</strong></p>
      @else
        <h3><i class="fas fa-plus-circle me-2 text-primary"></i> Add New Video</h3>
        <p>Create a new Ngendev video record</p>
      @endif
    </div>
    <a href="{{ route('ngendev.videos.index') }}" class="btn-back">
      <i class="fas fa-arrow-left"></i> Back to Videos
    </a>
  </div>

  @if($errors->any())
    <div class="alert alert-danger" style="border-radius:10px;font-size:13px;max-width:800px;">
      <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="form-card" style="max-width:800px;">
    <form method="POST"
          action="{{ $isEdit ? route('ngendev.videos.update', $video->id) : route('ngendev.videos.store') }}"
          enctype="multipart/form-data">
      @csrf
      @if($isEdit) @method('PUT') @endif

      <div class="row g-3">

        {{-- Category --}}
        <div class="col-md-6">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-select" required>
            <option value="">Select Category</option>
            @foreach($categories as $cat)
              <option value="{{ $cat->id }}"
                {{ old('category_id', $isEdit ? $video->category_id : '') == $cat->id ? 'selected' : '' }}>
                {{ $cat->category_name }}
              </option>
            @endforeach
          </select>
        </div>

        {{-- Name Change --}}
        <div class="col-md-6 d-flex align-items-end pb-1">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="name_change" id="nameChange"
                   {{ old('name_change', $isEdit ? $video->name_change : false) ? 'checked' : '' }}>
            <label class="form-check-label" for="nameChange" style="font-size:13px;font-weight:600;">
              Name Change
            </label>
          </div>
        </div>

        {{-- Prompt --}}
        <div class="col-md-12">
          <label class="form-label">
            Prompt
            <span id="char-count" style="color:#aaa;font-weight:400;">
              {{ strlen(old('ai_prompt', $isEdit ? $video->ai_prompt : '')) }}/3990
            </span>
          </label>
          <textarea name="ai_prompt" class="form-control" rows="5"
                    maxlength="3990" id="promptArea"
                    oninput="updateChar(this)"
                    required>{{ old('ai_prompt', $isEdit ? $video->ai_prompt : '') }}</textarea>
        </div>

        {{-- No of Video Image --}}
        @php
          $noOfVideoUrl = ($isEdit && $video->no_of_video && $video->category)
            ? asset('upload/ngendev/videos/' . $video->category->category_name . '/no_of_video_image/' . $video->no_of_video)
            : null;
        @endphp
        <div class="col-md-6">
          <label class="form-label">No of Video Image</label>
          <div class="ngd-dropzone">
            <input type="file" name="no_of_video" accept=".webp,image/webp" {{ $isEdit ? '' : 'required' }}>
            <div class="ngd-dz-body" @if($noOfVideoUrl) style="display:none" @endif>
              <i class="fas fa-cloud-upload-alt ngd-dz-icon"></i>
              <p class="ngd-dz-title">Drag & drop or click to upload</p>
              <p class="ngd-dz-sub">Drop your WebP image here</p>
              <span class="ngd-dz-badge">WebP only · Max 5 MB</span>
            </div>
            <div class="ngd-dz-preview" @if($noOfVideoUrl) style="display:flex" @endif>
              <img src="{{ $noOfVideoUrl ?? '' }}" alt="preview">
              <span class="ngd-dz-fname">{{ ($isEdit ? $video->no_of_video : '') ?? '' }}</span>
              <button type="button" class="ngd-dz-change">Change image</button>
            </div>
            <div class="ngd-dz-error-msg"></div>
          </div>
        </div>

        {{-- Video Thumbnail --}}
        @php
          $thumbUrl = ($isEdit && $video->video_thumbnail && $video->category)
            ? asset('upload/ngendev/videos/' . $video->category->category_name . '/video_thumbnail/' . $video->video_thumbnail)
            : null;
        @endphp
        <div class="col-md-6">
          <label class="form-label">Video Thumbnail</label>
          <div class="ngd-dropzone">
            <input type="file" name="video_thumbnail" accept=".webp,image/webp" {{ $isEdit ? '' : 'required' }}>
            <div class="ngd-dz-body" @if($thumbUrl) style="display:none" @endif>
              <i class="fas fa-image ngd-dz-icon"></i>
              <p class="ngd-dz-title">Drag & drop or click to upload</p>
              <p class="ngd-dz-sub">Drop your WebP image here</p>
              <span class="ngd-dz-badge">WebP only · Max 5 MB</span>
            </div>
            <div class="ngd-dz-preview" @if($thumbUrl) style="display:flex" @endif>
              <img src="{{ $thumbUrl ?? '' }}" alt="preview">
              <span class="ngd-dz-fname">{{ ($isEdit ? $video->video_thumbnail : '') ?? '' }}</span>
              <button type="button" class="ngd-dz-change">Change image</button>
            </div>
            <div class="ngd-dz-error-msg"></div>
          </div>
        </div>

        {{-- Video File --}}
        <div class="col-md-6">
          <label class="form-label">Video File</label>
          @if($isEdit && $video->video_path && $video->category)
            <div class="mb-2 d-flex align-items-center gap-2">
              <a href="javascript:void(0)" class="video-btn" title="Play current video"
                 data-stream-url="{{ route('ngendev.videos.stream', $video->id) }}">
                <i class="fas fa-play"></i>
              </a>
              <span class="file-hint">Current: {{ $video->video_path }}</span>
            </div>
          @endif
          <input type="file" name="video_path" class="form-control"
                 accept="video/mp4,video/quicktime,video/avi,video/webm"
                 {{ $isEdit ? '' : 'required' }}>
          @if($isEdit)
            <div class="file-hint">Leave blank to keep existing video</div>
          @else
            <div class="file-hint">Max 100 MB — MP4, MOV, AVI, WebM</div>
          @endif
        </div>

      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-submit" style="width:auto;padding:11px 32px;">
          @if($isEdit)
            <i class="fas fa-save me-2"></i> Update Video
          @else
            <i class="fas fa-plus me-2"></i> Add Video
          @endif
        </button>
        <a href="{{ route('ngendev.videos.index') }}" class="btn-back" style="padding:11px 20px;">
          Cancel
        </a>
      </div>

    </form>
  </div>

</div>
@endsection
