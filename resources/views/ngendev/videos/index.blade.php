@extends('layouts.kaiadmin')

@section('title', 'Videos Management')

@section('content')
<div class="page-inner">

  <div class="page-header-bar">
    <div class="header-left">
      <h3><i class="fas fa-video me-2 text-primary"></i> Ngendev Videos Management</h3>
      <p>Manage all Ngendev videos in the system</p>
    </div>
    <div class="header-right">
      <span class="stat-pill">
        <i class="fas fa-film"></i> Total: {{ $totalCount }} Videos
      </span>
      <a href="{{ route('ngendev.videos.create') }}" class="btn-add-cat">
        <i class="fas fa-plus me-1"></i> Add Video
      </a>
    </div>
  </div>

  <!-- ===== VIDEO TABLE ===== -->
  <div class="table-card">
    <div class="table-controls">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div class="show-entries">
          Show
          <select onchange="changePerPage(this.value)">
            <option value="10" {{ request('per_page',10)==10?'selected':'' }}>10</option>
            <option value="25" {{ request('per_page',10)==25?'selected':'' }}>25</option>
            <option value="50" {{ request('per_page',10)==50?'selected':'' }}>50</option>
          </select>
          entries
        </div>
        <form method="GET" action="{{ route('ngendev.videos.index') }}" id="filterForm" style="display:contents;">
          <div class="cat-filter">
            <select name="category_id" class="form-select" onchange="document.getElementById('filterForm').submit()">
              <option value="">All Categories</option>
              @foreach($categories as $cat)
                <option value="{{ $cat->id }}" {{ request('category_id')==$cat->id?'selected':'' }}>
                  {{ $cat->category_name }}
                </option>
              @endforeach
            </select>
          </div>
        </form>
      </div>
      <div class="search-box">
        <form method="GET" action="{{ route('ngendev.videos.index') }}" style="display:flex;gap:6px;">
          @if(request('category_id'))<input type="hidden" name="category_id" value="{{ request('category_id') }}">@endif
          <input type="text" name="search" value="{{ request('search') }}"
                 placeholder="Search by prompt or category...">
          <button type="submit" class="btn-filter"><i class="fas fa-search"></i></button>
        </form>
      </div>
    </div>

    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Category</th>
            <th>Thumbnail</th>
            <th>Video</th>
            <th>Prompt</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($videos as $video)
          <tr>
            <td><strong>{{ $video->category->category_name ?? '—' }}</strong></td>
            <td>
              @if($video->video_thumbnail && $video->category)
                <img src="{{ asset('upload/ngendev/videos/' . $video->category->category_name . '/video_thumbnail/' . $video->video_thumbnail) }}"
                     class="thumb-img" alt="thumb"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                <span class="thumb-placeholder" style="display:none;"><i class="fas fa-image"></i></span>
              @else
                <span class="thumb-placeholder"><i class="fas fa-image"></i></span>
              @endif
            </td>
            <td>
              @if($video->video_path && $video->category)
                <a href="javascript:void(0)" class="video-btn" title="Play video"
                   data-stream-url="{{ route('ngendev.videos.stream', $video->id) }}">
                  <i class="fas fa-play"></i>
                </a>
              @else
                <span class="no-video"><i class="fas fa-minus"></i></span>
              @endif
            </td>
            <td>
              <div class="prompt-cell" title="{{ $video->ai_prompt }}">
                {{ Str::limit($video->ai_prompt, 55) }}
              </div>
            </td>
            <td>
              <div style="display:flex;gap:6px;">
                <a href="{{ route('ngendev.videos.edit', $video->id) }}" class="btn-edit" title="Edit">
                  <i class="fas fa-pencil-alt"></i>
                </a>
                <form method="POST" action="{{ route('ngendev.videos.destroy', $video->id) }}"
                      onsubmit="return confirm('Delete this video record?')">
                  @csrf @method('DELETE')
                  <button type="submit" class="btn-del" title="Delete">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="5" class="text-center text-muted py-4">No videos found.</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="pagination-row">
      <div class="pagination-info">
        Showing {{ $videos->firstItem() ?? 0 }} to {{ $videos->lastItem() ?? 0 }}
        of {{ $videos->total() }} entries
      </div>
      <div class="pagination-btns">
        @if($videos->onFirstPage())
          <span class="page-btn page-btn-arrow" style="opacity:.4;cursor:default;">
            <i class="fas fa-chevron-left"></i>
          </span>
        @else
          <a class="page-btn page-btn-arrow" href="{{ $videos->previousPageUrl() }}">
            <i class="fas fa-chevron-left"></i>
          </a>
        @endif

        @foreach($videos->getUrlRange(1, $videos->lastPage()) as $page => $url)
          <a class="page-btn {{ $page == $videos->currentPage() ? 'active' : '' }}"
             href="{{ $url }}">{{ $page }}</a>
        @endforeach

        @if($videos->hasMorePages())
          <a class="page-btn page-btn-arrow" href="{{ $videos->nextPageUrl() }}">
            <i class="fas fa-chevron-right"></i>
          </a>
        @else
          <span class="page-btn page-btn-arrow" style="opacity:.4;cursor:default;">
            <i class="fas fa-chevron-right"></i>
          </span>
        @endif
      </div>
    </div>
  </div>

</div>
@endsection
