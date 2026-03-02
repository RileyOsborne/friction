<?php

use App\Models\Category;
use App\Models\Topic;
use App\Models\Answer;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('Categories')] class extends Component {
    use WithPagination;

    public string $search = '';
    public ?string $topicFilter = null;
    public int $perPage = 10;
    public string $sortBy = 'title';
    public string $sortDirection = 'asc';

    // Confirmation modals
    public bool $showImportModal = false;
    public bool $showRemoveModal = false;
    public bool $showDeleteTopicModal = false;
    public ?string $topicToDelete = null;
    public ?string $topicToDeleteName = null;
    public bool $showDeleteCategoryModal = false;
    public ?string $categoryToDelete = null;
    public ?string $categoryToDeleteName = null;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    // Topic management
    public bool $showTopicModal = false;
    public ?string $editingTopicId = null;
    public string $topicName = '';

    public bool $showArchived = false;
    public ?string $categoryToRestore = null;
    public ?string $categoryToForceDelete = null;
    public bool $showRestoreCategoryModal = false;
    public bool $showForceDeleteCategoryModal = false;

    public function toggleArchived(): void
    {
        $this->showArchived = !$this->showArchived;
        $this->resetPage();
    }

    public function updatedTopicFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->topicFilter = null;
        $this->resetPage();
    }

    public bool $showArchivedTopics = false;
    public ?string $topicToRestore = null;
    public ?string $topicToForceDelete = null;
    public bool $showRestoreTopicModal = false;
    public bool $showForceDeleteTopicModal = false;

    public function toggleArchivedTopics(): void
    {
        $this->showArchivedTopics = !$this->showArchivedTopics;
    }

    public function confirmRestoreTopic(string $topicId, string $topicName): void
    {
        $this->topicToRestore = $topicId;
        $this->topicToDeleteName = $topicName;
        $this->showRestoreTopicModal = true;
    }

    public function restoreTopic(): void
    {
        if (!$this->topicToRestore) return;
        $topic = Topic::onlyTrashed()->find($this->topicToRestore);
        if ($topic) {
            $topic->restore();
            session()->flash('message', 'Topic restored successfully.');
        }
        $this->showRestoreTopicModal = false;
        $this->topicToRestore = null;
    }

    public function confirmForceDeleteTopic(string $topicId, string $topicName): void
    {
        $this->topicToForceDelete = $topicId;
        $this->topicToDeleteName = $topicName;
        $this->showForceDeleteTopicModal = true;
    }

    public function forceDeleteTopic(): void
    {
        if (!$this->topicToForceDelete) return;
        $topic = Topic::onlyTrashed()->find($this->topicToForceDelete);
        if ($topic) {
            $topic->forceDelete();
            session()->flash('message', 'Topic permanently deleted.');
        }
        $this->showForceDeleteTopicModal = false;
        $this->topicToForceDelete = null;
    }

    public function with(): array
    {
        return [
            'categories' => Category::query()
                ->when($this->showArchived, fn($q) => $q->onlyTrashed(), fn($q) => $q->withoutTrashed())
                ->with('topic')
                ->when($this->search, fn($q) => $q->where('title', 'like', "%{$this->search}%"))
                ->when($this->topicFilter, fn($q) => $q->where('topic_id', $this->topicFilter))
                ->withCount([
                    'answers as base_count' => fn($q) => $q->where('position', '<=', 10),
                    'answers as friction_count' => fn($q) => $q->where('position', '>', 10),
                ])
                ->when($this->sortBy === 'topic', function($q) {
                    $q->orderBy(
                        \App\Models\Topic::select('name')
                            ->whereColumn('topics.id', 'categories.topic_id')
                            ->limit(1),
                        $this->sortDirection
                    );
                }, function($q) {
                    $q->orderBy($this->sortBy, $this->sortDirection);
                })
                ->paginate($this->perPage),
            'totalCategories' => Category::count(),
            'topics' => Topic::query()
                ->when($this->showArchivedTopics, fn($q) => $q->onlyTrashed(), fn($q) => $q->withoutTrashed())
                ->withCount('categories')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function openTopicModal(?Topic $topic = null): void
    {
        if ($topic && $topic->exists) {
            $this->editingTopicId = $topic->id;
            $this->topicName = $topic->name;
        } else {
            $this->editingTopicId = null;
            $this->topicName = '';
        }
        $this->showTopicModal = true;
    }

    public function closeTopicModal(): void
    {
        $this->showTopicModal = false;
        $this->editingTopicId = null;
        $this->topicName = '';
    }

    public function saveTopic(): void
    {
        $this->validate(['topicName' => 'required|string|max:255']);

        if ($this->editingTopicId) {
            Topic::where('id', $this->editingTopicId)->update(['name' => $this->topicName]);
            session()->flash('message', 'Topic updated successfully.');
        } else {
            Topic::create(['name' => $this->topicName]);
            session()->flash('message', 'Topic created successfully.');
        }

        $this->closeTopicModal();
    }

    public function confirmDeleteTopic(string $topicId, string $topicName): void
    {
        $this->topicToDelete = $topicId;
        $this->topicToDeleteName = $topicName;
        $this->showDeleteTopicModal = true;
    }

    public function cancelDeleteTopic(): void
    {
        $this->showDeleteTopicModal = false;
        $this->topicToDelete = null;
        $this->topicToDeleteName = null;
    }

    public function deleteTopic(): void
    {
        if (!$this->topicToDelete) return;

        $topic = Topic::find($this->topicToDelete);
        if ($topic) {
            if ($this->topicFilter === $topic->id) {
                $this->topicFilter = null;
            }
            $topic->delete();
            session()->flash('message', 'Topic archived successfully. Categories have been unassigned.');
        }
        $this->cancelDeleteTopic();
    }

    public function confirmDeleteCategory(string $categoryId, string $categoryTitle): void
    {
        $this->categoryToDelete = $categoryId;
        $this->categoryToDeleteName = $categoryTitle;
        $this->showDeleteCategoryModal = true;
    }

    public function cancelDeleteCategory(): void
    {
        $this->showDeleteCategoryModal = false;
        $this->categoryToDelete = null;
        $this->categoryToDeleteName = null;
    }

    public function deleteCategory(): void
    {
        if (!$this->categoryToDelete) return;

        $category = Category::find($this->categoryToDelete);
        if ($category) {
            $category->delete();
            session()->flash('message', 'Category archived successfully.');
        }
        $this->cancelDeleteCategory();
    }

    public function confirmRestoreCategory(string $categoryId, string $categoryTitle): void
    {
        $this->categoryToRestore = $categoryId;
        $this->categoryToDeleteName = $categoryTitle;
        $this->showRestoreCategoryModal = true;
    }

    public function cancelRestoreCategory(): void
    {
        $this->showRestoreCategoryModal = false;
        $this->categoryToRestore = null;
        $this->categoryToDeleteName = null;
    }

    public function restoreCategory(): void
    {
        if (!$this->categoryToRestore) return;

        $category = Category::onlyTrashed()->find($this->categoryToRestore);
        if ($category) {
            $category->restore();
            session()->flash('message', 'Category restored successfully.');
        }
        $this->cancelRestoreCategory();
    }

    public function confirmForceDeleteCategory(string $categoryId, string $categoryTitle): void
    {
        $this->categoryToForceDelete = $categoryId;
        $this->categoryToDeleteName = $categoryTitle;
        $this->showForceDeleteCategoryModal = true;
    }

    public function cancelForceDeleteCategory(): void
    {
        $this->showForceDeleteCategoryModal = false;
        $this->categoryToForceDelete = null;
        $this->categoryToDeleteName = null;
    }

    public function forceDeleteCategory(): void
    {
        if (!$this->categoryToForceDelete) return;

        $category = Category::onlyTrashed()->find($this->categoryToForceDelete);
        if ($category) {
            $category->forceDelete();
            session()->flash('message', 'Category permanently deleted.');
        }
        $this->cancelForceDeleteCategory();
    }

    public function setCategoryTopic(Category $category, ?string $topicId): void
    {
        $category->update(['topic_id' => $topicId ?: null]);
    }

    public function togglePlayed(Category $category): void
    {
        $category->update([
            'played_at' => $category->played_at ? null : now(),
        ]);
    }

    public function importStarterPack(): void
    {
        $this->showImportModal = false;
        
        try {
            \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'StarterPackSeeder']);
            session()->flash('message', 'Starter pack imported successfully.');
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to import starter pack: ' . $e->getMessage());
        }
    }

    public function removeStarterPack(): void
    {
        $this->showRemoveModal = false;
        $count = Category::where('is_starter', true)->count();
        Category::where('is_starter', true)->delete();
        session()->flash('message', "Archived {$count} starter pack categories.");
    }

    public function hasStarterPack(): bool
    {
        return Category::where('is_starter', true)->exists();
    }

    public function getStarterPackCount(): int
    {
        $path = database_path('data/starter-pack.php');
        if (!file_exists($path)) {
            return 0;
        }
        $data = require $path;
        return count($data['categories'] ?? []);
    }

    public function confirmImport(): void
    {
        $this->showImportModal = true;
    }

    public function confirmRemove(): void
    {
        $this->showRemoveModal = true;
    }

    public function closeModals(): void
    {
        $this->showImportModal = false;
        $this->showRemoveModal = false;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">Categories</h1>
            <div class="flex gap-3">
                @if($this->hasStarterPack())
                    <button wire:click="confirmRemove"
                            class="bg-red-600/20 hover:bg-red-600/30 text-red-400 px-4 py-3 rounded-lg font-semibold transition border border-red-600/50">
                        Remove Starter Pack
                    </button>
                @else
                    <button wire:click="confirmImport"
                            class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-3 rounded-lg font-semibold transition">
                        Import Starter Pack
                    </button>
                @endif
                <a href="{{ route('categories.create') }}"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition">
                    + New Category
                </a>
            </div>
        </div>

        @if (session('message'))
            <div class="bg-green-600/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-6">
                {{ session('message') }}
            </div>
        @endif

        @if (session('error'))
            <div class="bg-red-600/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg mb-6">
                {{ session('error') }}
            </div>
        @endif

        <div class="flex gap-8">
            <!-- Topics Sidebar -->
            <div class="w-64 flex-shrink-0">
                <div class="bg-slate-800 rounded-xl border border-slate-700 p-4">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex flex-col">
                            <h2 class="font-semibold text-lg">Topics</h2>
                            <button wire:click="toggleArchivedTopics" class="text-[10px] uppercase tracking-widest font-bold {{ $showArchivedTopics ? 'text-yellow-500' : 'text-slate-500 hover:text-slate-400' }} transition text-left">
                                {{ $showArchivedTopics ? 'Showing Archived' : 'Show Archived' }}
                            </button>
                        </div>
                        <button wire:click="openTopicModal"
                                class="text-blue-400 hover:text-blue-300 text-sm font-medium">
                            + Add
                        </button>
                    </div>

                    <div class="space-y-1">
                        @if(!$showArchivedTopics)
                            <button wire:click="$set('topicFilter', null)"
                                    class="w-full text-left px-3 py-2 rounded-lg transition {{ $topicFilter === null ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">
                                All Topics
                                <span class="text-xs opacity-70">({{ $totalCategories }})</span>
                            </button>
                        @endif

                        @foreach($topics as $topic)
                            <div class="flex items-center group">
                                <button wire:click="$set('topicFilter', '{{ $topic->id }}')"
                                        class="flex-1 text-left px-3 py-2 rounded-lg transition {{ $topicFilter === $topic->id ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">
                                    {{ $topic->name }}
                                    <span class="text-xs opacity-70">({{ $topic->categories_count }})</span>
                                </button>
                                <div class="opacity-0 group-hover:opacity-100 transition flex">
                                    @if($topic->trashed())
                                        <button wire:click="confirmRestoreTopic('{{ $topic->id }}', '{{ addslashes($topic->name) }}')"
                                                class="p-1 text-green-500 hover:text-green-400" title="Restore Topic">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                            </svg>
                                        </button>
                                        <button wire:click="confirmForceDeleteTopic('{{ $topic->id }}', '{{ addslashes($topic->name) }}')"
                                                class="p-1 text-red-600 hover:text-red-500" title="Permanently Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    @else
                                        <button wire:click="openTopicModal('{{ $topic->id }}')"
                                                class="p-1 text-slate-400 hover:text-white">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                            </svg>
                                        </button>
                                        <button wire:click="confirmDeleteTopic('{{ $topic->id }}', '{{ addslashes($topic->name) }}')"
                                                class="p-1 text-slate-400 hover:text-red-400" title="Archive Topic">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1">
                <!-- Search and Filters -->
                <div class="flex items-center gap-4 mb-6">
                    <div class="flex-1 relative">
                        <input type="text"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Search categories..."
                               class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-3 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                    </div>

                    @if($search || $topicFilter)
                        <button wire:click="clearFilters"
                                class="text-sm font-medium text-slate-400 hover:text-white flex items-center gap-2 px-2 transition group">
                            <span class="p-1 rounded-md bg-slate-800 group-hover:bg-slate-700 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </span>
                            Clear Filters
                        </button>
                    @endif

                    <button wire:click="toggleArchived"
                            class="px-4 py-3 rounded-lg font-semibold transition border {{ $showArchived ? 'bg-yellow-600/20 border-yellow-600 text-yellow-400' : 'bg-slate-800 border-slate-700 text-slate-400 hover:text-white' }}">
                        {{ $showArchived ? 'Viewing Archived' : 'Show Archived' }}
                    </button>
                </div>

                @if($categories->isEmpty())
                    <div class="bg-slate-800 rounded-xl p-12 text-center border border-slate-700">
                        <p class="text-slate-400 text-lg mb-4">No categories found.</p>
                        <div class="flex justify-center gap-4">
                            @if($search || $topicFilter)
                                <button wire:click="clearFilters" class="text-blue-400 hover:text-blue-300 font-medium">
                                    Clear filters
                                </button>
                            @endif
                            <a href="{{ route('categories.create') }}" class="text-slate-300 hover:text-white font-medium">
                                Create a category
                            </a>
                        </div>
                    </div>
                @else
                    <div class="bg-slate-800 rounded-xl border border-slate-700 overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-900/50">
                                <tr class="text-left text-sm text-slate-400">
                                    <th class="px-4 py-3 font-medium w-[40%]">
                                        <button wire:click="sort('title')" class="flex items-center gap-1 hover:text-white transition">
                                            Title
                                            <svg class="w-4 h-4 {{ $sortBy === 'title' ? 'text-white' : 'text-slate-600' }} {{ $sortBy === 'title' && $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                    </th>
                                    <th class="px-4 py-3 font-medium w-[15%]">
                                        <button wire:click="sort('topic')" class="flex items-center gap-1 hover:text-white transition">
                                            Topic
                                            <svg class="w-4 h-4 {{ $sortBy === 'topic' ? 'text-white' : 'text-slate-600' }} {{ $sortBy === 'topic' && $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                    </th>
                                    <th class="px-4 py-3 font-medium text-center w-[10%]">Base</th>
                                    <th class="px-4 py-3 font-medium text-center w-[10%]">Friction</th>
                                    <th class="px-4 py-3 font-medium text-center w-[10%]">
                                        <button wire:click="sort('played_at')" class="flex items-center gap-1 hover:text-white transition mx-auto">
                                            Played
                                            <svg class="w-4 h-4 {{ $sortBy === 'played_at' ? 'text-white' : 'text-slate-600' }} {{ $sortBy === 'played_at' && $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                    </th>
                                    <th class="px-4 py-3 font-medium text-right w-[15%]">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                @foreach($categories as $category)
                                    <tr class="hover:bg-slate-700/30 transition group">
                                        <td class="px-4 py-3">
                                            <a href="{{ route('categories.show', $category) }}" class="font-medium text-white hover:text-blue-400 transition">
                                                {{ $category->title }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3" wire:click.stop>
                                            <select wire:change="setCategoryTopic('{{ $category->id }}', $event.target.value)"
                                                    class="bg-transparent border-0 text-sm text-slate-300 focus:outline-none focus:ring-0 cursor-pointer hover:text-white -ml-1 py-0 pr-6">
                                                <option value="" class="bg-slate-800" {{ !$category->topic_id ? 'selected' : '' }}>None</option>
                                                @foreach($topics as $topic)
                                                    <option value="{{ $topic->id }}" class="bg-slate-800" {{ $category->topic_id === $topic->id ? 'selected' : '' }}>
                                                        {{ $topic->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="text-sm {{ $category->base_count >= 10 ? 'text-green-400' : 'text-yellow-400' }}">
                                                {{ $category->base_count }}/10
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="text-sm {{ $category->friction_count >= 1 ? 'text-red-400' : 'text-yellow-400' }}">
                                                {{ $category->friction_count }}/5
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center" wire:click.stop>
                                            <button wire:click="togglePlayed('{{ $category->id }}')"
                                                    class="px-3 py-1 rounded text-sm font-medium transition {{ $category->played_at ? 'bg-slate-600 text-slate-200 hover:bg-slate-500' : 'bg-green-600 text-white hover:bg-green-500' }}">
                                                {{ $category->played_at ? 'Played' : 'New' }}
                                            </button>
                                        </td>
                                        <td class="px-4 py-3 text-right" wire:click.stop>
                                            @if($category->trashed())
                                                <button wire:click="confirmRestoreCategory('{{ $category->id }}', '{{ addslashes($category->title) }}')"
                                                        class="text-green-400 hover:text-green-300 text-sm font-medium mr-3">
                                                    Restore
                                                </button>
                                                <button wire:click="confirmForceDeleteCategory('{{ $category->id }}', '{{ addslashes($category->title) }}')"
                                                        class="text-red-500 hover:text-red-400 text-sm font-medium">
                                                    Burn
                                                </button>
                                            @else
                                                <a href="{{ route('categories.edit', $category) }}"
                                                   class="text-blue-400 hover:text-blue-300 text-sm font-medium mr-3">
                                                    Edit
                                                </a>
                                                <button wire:click="confirmDeleteCategory('{{ $category->id }}', '{{ addslashes($category->title) }}')"
                                                        class="text-red-400 hover:text-red-300 text-sm font-medium">
                                                    Archive
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <span class="text-sm text-slate-400">
                                Showing {{ $categories->firstItem() ?? 0 }} to {{ $categories->lastItem() ?? 0 }} of {{ $categories->total() }}
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-slate-500">Per page:</span>
                                @foreach([10, 20, 50] as $size)
                                    <button wire:click="$set('perPage', {{ $size }})"
                                            class="px-2 py-1 text-sm rounded transition {{ $perPage === $size ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700' }}">
                                        {{ $size }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            {{ $categories->links('vendor.livewire.simple') }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Topic Modal -->
    @if($showTopicModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="closeTopicModal"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold">{{ $editingTopicId ? 'Edit Topic' : 'New Topic' }}</h3>
                        <button wire:click="closeTopicModal" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <form wire:submit="saveTopic" class="p-6">
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-slate-300 mb-2">Topic Name</label>
                            <input type="text"
                                   wire:model="topicName"
                                   placeholder="e.g., Geography, Movies, Science"
                                   class="w-full bg-slate-900 border border-slate-600 rounded-lg px-4 py-2 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   autofocus>
                            @error('topicName') <span class="text-red-400 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div class="flex justify-end gap-3">
                            <button type="button"
                                    wire:click="closeTopicModal"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-medium transition">
                                {{ $editingTopicId ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- Import Starter Pack Modal -->
    @if($showImportModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="closeModals"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold">Import Starter Pack</h3>
                        <button wire:click="closeModals" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-6">
                            This will add <span class="font-semibold text-white">{{ $this->getStarterPackCount() }} ready-to-play categories</span> across multiple topics to help you get started.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="closeModals"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="importStarterPack" wire:click.prefetch="closeModals"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-medium transition">
                                Import
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Remove Starter Pack Modal -->
    @if($showRemoveModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="closeModals"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-red-400">Remove Starter Pack</h3>
                        <button wire:click="closeModals" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            This will remove all starter pack categories from your library.
                        </p>
                        <p class="text-slate-400 text-sm mb-6">
                            You can always import them again later.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="closeModals"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="removeStarterPack" wire:click.prefetch="closeModals"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-medium transition">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Delete Topic Modal (Archive) -->
    @if($showDeleteTopicModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="cancelDeleteTopic"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-yellow-400">Archive Topic</h3>
                        <button wire:click="cancelDeleteTopic" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            Are you sure you want to archive <span class="font-semibold text-white">{{ $topicToDeleteName }}</span>?
                        </p>
                        <p class="text-slate-400 text-sm mb-6">
                            Categories will be unassigned but not deleted. You can restore this topic later.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="cancelDeleteTopic"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="deleteTopic"
                                    class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 rounded-lg font-medium transition text-white">
                                Archive Topic
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Restore Topic Modal -->
    @if($showRestoreTopicModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="$set('showRestoreTopicModal', false)"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-green-400">Restore Topic</h3>
                        <button wire:click="$set('showRestoreTopicModal', false)" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-6">
                            Restore <span class="font-semibold text-white">{{ $topicToDeleteName }}</span> to your active topics?
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="$set('showRestoreTopicModal', false)"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="restoreTopic"
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg font-medium transition">
                                Restore Topic
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Force Delete Topic Modal -->
    @if($showForceDeleteTopicModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="$set('showForceDeleteTopicModal', false)"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-red-500">Permanently Delete Topic</h3>
                        <button wire:click="$set('showForceDeleteTopicModal', false)" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            Delete <span class="font-semibold text-white">{{ $topicToDeleteName }}</span> permanently?
                        </p>
                        <p class="text-red-400 text-sm font-bold mb-6">
                            This action is destructive and cannot be undone.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="$set('showForceDeleteTopicModal', false)"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="forceDeleteTopic"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-medium transition">
                                Burn Forever
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Delete Category Modal (Archive) -->
    @if($showDeleteCategoryModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="cancelDeleteCategory"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-yellow-400">Archive Category</h3>
                        <button wire:click="cancelDeleteCategory" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            Are you sure you want to archive <span class="font-semibold text-white">{{ $categoryToDeleteName }}</span>?
                        </p>
                        <p class="text-slate-400 text-sm mb-6">
                            It will be hidden from the game but can be restored later.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="cancelDeleteCategory"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="deleteCategory"
                                    class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 rounded-lg font-medium transition text-white">
                                Archive Category
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Restore Category Modal -->
    @if($showRestoreCategoryModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="cancelRestoreCategory"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-green-400">Restore Category</h3>
                        <button wire:click="cancelRestoreCategory" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-6">
                            Restore <span class="font-semibold text-white">{{ $categoryToDeleteName }}</span> to your active library?
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="cancelRestoreCategory"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="restoreCategory"
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg font-medium transition">
                                Restore
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Force Delete Category Modal -->
    @if($showForceDeleteCategoryModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="cancelForceDeleteCategory"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-red-500">Permanently Delete</h3>
                        <button wire:click="cancelForceDeleteCategory" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            Delete <span class="font-semibold text-white">{{ $categoryToDeleteName }}</span> permanently?
                        </p>
                        <p class="text-red-400 text-sm font-bold mb-6">
                            This action is destructive and cannot be undone.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="cancelForceDeleteCategory"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="forceDeleteCategory"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-medium transition">
                                Burn Forever
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
