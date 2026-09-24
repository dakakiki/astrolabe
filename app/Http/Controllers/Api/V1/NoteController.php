<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notes\SaveNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    /**
     * A client's notes, or those on one consultation, newest first. Other people's
     * private notes are left out.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Note::class);

        $filters = $request->validate([
            'client_id' => ['required_without:consultation_id', 'nullable', 'integer'],
            'consultation_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $notes = Note::query()
            ->visibleTo($request->user())
            ->with(['author', 'consultation'])
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['consultation_id'] ?? null, fn (Builder $query, int $id) => $query->where('consultation_id', $id))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return NoteResource::collection($notes);
    }

    public function store(SaveNoteRequest $request, SaveNote $save): NoteResource
    {
        return NoteResource::make($save->handle(new Note, $request->validated()));
    }

    public function show(Note $note): NoteResource
    {
        Gate::authorize('view', $note);

        return NoteResource::make($note->load(['author', 'consultation']));
    }

    public function update(SaveNoteRequest $request, Note $note, SaveNote $save): NoteResource
    {
        return NoteResource::make($save->handle($note, $request->validated()));
    }

    public function destroy(Note $note): Response
    {
        Gate::authorize('delete', $note);

        $note->delete();

        return response()->noContent();
    }
}
