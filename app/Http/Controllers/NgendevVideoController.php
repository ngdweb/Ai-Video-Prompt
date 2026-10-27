<?php

namespace App\Http\Controllers;

use App\Models\NgendevVideo;
use App\Models\NgendevVideoCategory;
use Illuminate\Http\Request;

class NgendevVideoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:admin');
    }

    /**
     * Stream a video's bytes to the browser without ever exposing a media URL.
     * Reached only via an authenticated + CSRF-protected POST, so there is nothing
     * in the page to copy and nothing that streams without an admin session.
     */
    public function stream(NgendevVideo $video)
    {
        if (!$video->video_path || !$video->category) {
            abort(404, 'Video not found.');
        }

        $relativePath = 'upload/ngendev/videos/'
            . $video->category->category_name
            . '/category_video/'
            . $video->video_path;

        $realUploadRoot = realpath(public_path('upload'));
        $realTarget     = realpath(public_path($relativePath));

        if (!$realTarget || !$realUploadRoot || !str_starts_with($realTarget, $realUploadRoot) || !is_file($realTarget)) {
            abort(404, 'Video file not found.');
        }

        $extension = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
        $mimeMap = [
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'webm' => 'video/webm',
        ];
        $mimeType = $mimeMap[$extension] ?? (mime_content_type($realTarget) ?: 'application/octet-stream');

        return response()->file($realTarget, [
            'Content-Type'           => $mimeType,
            'Cache-Control'          => 'private, no-cache, no-store, must-revalidate',
            'Pragma'                 => 'no-cache',
            'Expires'                => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ]);
    }

    public function index(Request $request)
    {
        $categories = NgendevVideoCategory::orderBy('category_name')->get();

        $query = NgendevVideo::with('category');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($sq) use ($q) {
                $sq->where('ai_prompt', 'like', "%{$q}%")
                   ->orWhere('ai_model', 'like', "%{$q}%")
                   ->orWhereHas('category', fn($c) => $c->where('category_name', 'like', "%{$q}%"));
            });
        }

        $perPage    = (int) $request->get('per_page', 10);
        $videos     = $query->orderBy('category_id')->orderBy('id')->paginate($perPage)->withQueryString();
        $totalCount = NgendevVideo::count();

        return view('ngendev.videos.index', compact('categories', 'videos', 'totalCount'));
    }

    public function create()
    {
        $categories = NgendevVideoCategory::orderBy('category_name')->get();
        return view('ngendev.videos.form', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id'     => 'required|exists:ngendev_video_categories,id',
            'ai_prompt'       => 'required|string|max:3990',
            'no_of_video'     => 'required|mimes:webp|max:5120',
            'video_thumbnail' => 'required|mimes:webp|max:5120',
            'video_path'      => 'required|mimes:mp4,mov,avi,webm|max:102400',
        ]);

        $category  = NgendevVideoCategory::findOrFail($request->category_id);
        $catFolder = $category->category_name;

        $noOfVideoName = null;
        if ($request->hasFile('no_of_video')) {
            $file          = $request->file('no_of_video');
            $noOfVideoName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
            $destDir       = public_path('upload/ngendev/videos/' . $catFolder . '/no_of_video_image');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $file->move($destDir, $noOfVideoName);
        }

        $thumbnailName = null;
        if ($request->hasFile('video_thumbnail')) {
            $file          = $request->file('video_thumbnail');
            $thumbnailName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
            $destDir       = public_path('upload/ngendev/videos/' . $catFolder . '/video_thumbnail');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $file->move($destDir, $thumbnailName);
        }

        $videoName = null;
        if ($request->hasFile('video_path')) {
            $file      = $request->file('video_path');
            $videoName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
            $destDir   = public_path('upload/ngendev/videos/' . $catFolder . '/category_video');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $file->move($destDir, $videoName);
        }

        NgendevVideo::create([
            'category_id'     => $request->category_id,
            'ai_prompt'       => $request->ai_prompt,
            'no_of_video'     => $noOfVideoName,
            'name_change'     => $request->has('name_change') ? 1 : 0,
            'video_thumbnail' => $thumbnailName,
            'video_path'      => $videoName,
        ]);

        return redirect()->route('ngendev.videos.index')
                         ->with('success', 'Video added successfully.');
    }

    public function edit(NgendevVideo $video)
    {
        $categories = NgendevVideoCategory::orderBy('category_name')->get();
        return view('ngendev.videos.form', compact('video', 'categories'));
    }

    public function update(Request $request, NgendevVideo $video)
    {
        $request->validate([
            'category_id'     => 'required|exists:ngendev_video_categories,id',
            'ai_prompt'       => 'required|string|max:3990',
            'no_of_video'     => 'nullable|mimes:webp|max:5120',
            'video_thumbnail' => 'nullable|mimes:webp|max:5120',
            'video_path'      => 'nullable|mimes:mp4,mov,avi,webm|max:102400',
        ]);

        $category        = NgendevVideoCategory::findOrFail($request->category_id);
        $catFolder       = $category->category_name;
        $oldCategory     = $video->category;
        $categoryChanged = $oldCategory && $oldCategory->id != $request->category_id;

        // ── no_of_video image ──────────────────────────────────────────────
        $noOfVideoName = $video->no_of_video;
        if ($request->hasFile('no_of_video')) {
            if ($video->no_of_video && $oldCategory) {
                $this->deleteFile(public_path('upload/ngendev/videos/' . $oldCategory->category_name . '/no_of_video_image/' . $video->no_of_video));
            }
            $file          = $request->file('no_of_video');
            $noOfVideoName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
            $destDir       = public_path('upload/ngendev/videos/' . $catFolder . '/no_of_video_image');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $file->move($destDir, $noOfVideoName);
        } elseif ($categoryChanged && $video->no_of_video && $oldCategory) {
            $this->moveFile(
                public_path('upload/ngendev/videos/' . $oldCategory->category_name . '/no_of_video_image/' . $video->no_of_video),
                public_path('upload/ngendev/videos/' . $catFolder . '/no_of_video_image'),
                $video->no_of_video
            );
        }

        // ── thumbnail ──────────────────────────────────────────────────────
        $thumbnailName = $video->video_thumbnail;
        if ($request->hasFile('video_thumbnail')) {
            if ($video->video_thumbnail && $oldCategory) {
                $this->deleteFile(public_path('upload/ngendev/videos/' . $oldCategory->category_name . '/video_thumbnail/' . $video->video_thumbnail));
            }
            $file          = $request->file('video_thumbnail');
            $thumbnailName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
            $destDir       = public_path('upload/ngendev/videos/' . $catFolder . '/video_thumbnail');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $file->move($destDir, $thumbnailName);
        } elseif ($categoryChanged && $video->video_thumbnail && $oldCategory) {
            $this->moveFile(
                public_path('upload/ngendev/videos/' . $oldCategory->category_name . '/video_thumbnail/' . $video->video_thumbnail),
                public_path('upload/ngendev/videos/' . $catFolder . '/video_thumbnail'),
                $video->video_thumbnail
            );
        }

        // ── video file ─────────────────────────────────────────────────────
        $videoName = $video->video_path;
        if ($request->hasFile('video_path')) {
            if ($video->video_path && $oldCategory) {
                $this->deleteFile(public_path('upload/ngendev/videos/' . $oldCategory->category_name . '/category_video/' . $video->video_path));
            }
            $file      = $request->file('video_path');
            $videoName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
            $destDir   = public_path('upload/ngendev/videos/' . $catFolder . '/category_video');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $file->move($destDir, $videoName);
        } elseif ($categoryChanged && $video->video_path && $oldCategory) {
            $this->moveFile(
                public_path('upload/ngendev/videos/' . $oldCategory->category_name . '/category_video/' . $video->video_path),
                public_path('upload/ngendev/videos/' . $catFolder . '/category_video'),
                $video->video_path
            );
        }

        $video->update([
            'category_id'     => $request->category_id,
            'ai_prompt'       => $request->ai_prompt,
            'no_of_video'     => $noOfVideoName,
            'name_change'     => $request->has('name_change') ? 1 : 0,
            'video_thumbnail' => $thumbnailName,
            'video_path'      => $videoName,
        ]);

        return redirect()->route('ngendev.videos.index')
                         ->with('success', 'Video updated successfully.');
    }

    public function destroy(NgendevVideo $video)
    {
        $catName = $video->category ? $video->category->category_name : null;

        if ($catName) {
            if ($video->no_of_video) {
                $this->deleteFile(public_path('upload/ngendev/videos/' . $catName . '/no_of_video_image/' . $video->no_of_video));
            }
            if ($video->video_thumbnail) {
                $this->deleteFile(public_path('upload/ngendev/videos/' . $catName . '/video_thumbnail/' . $video->video_thumbnail));
            }
            if ($video->video_path) {
                $this->deleteFile(public_path('upload/ngendev/videos/' . $catName . '/category_video/' . $video->video_path));
            }
        }

        $video->delete();

        return redirect()->route('ngendev.videos.index')
                         ->with('success', 'Video deleted.');
    }

    /* ── File helpers ──────────────────────────────────────────────────── */

    private function deleteFile(string $path): void
    {
        if (!file_exists($path)) return;
        unlink($path);
        $dir = dirname($path);
        if (is_dir($dir) && empty(array_diff(scandir($dir), ['.', '..']))) {
            rmdir($dir);
        }
    }

    private function moveFile(string $oldPath, string $newDir, string $filename): void
    {
        if (!file_exists($oldPath)) return;
        if (!is_dir($newDir)) mkdir($newDir, 0755, true);
        rename($oldPath, $newDir . '/' . $filename);
        $oldDir = dirname($oldPath);
        if (is_dir($oldDir) && empty(array_diff(scandir($oldDir), ['.', '..']))) {
            rmdir($oldDir);
        }
    }

    public function updateNameChange(Request $request)
    {
        $video = NgendevVideo::findOrFail($request->id);
        $video->update(['name_change' => $request->value]);
        return response()->json(['success' => true]);
    }

    public function reindex()
    {
        $videos = NgendevVideo::orderBy('category_id')->orderBy('id')->get();
        foreach ($videos as $i => $v) {
            $v->update(['sort_order' => $i]);
        }
        return redirect()->route('ngendev.videos.index')
                         ->with('success', 'Videos reindexed.');
    }
}
