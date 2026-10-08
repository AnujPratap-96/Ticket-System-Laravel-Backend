<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ArticleSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ArticleController extends Controller
{
    /**
     * Public help centre search (published articles only).
     */
    public function search(Request $request, ArticleSearchService $semantic): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:60'], 'limit' => ['nullable', 'integer', 'min:1', 'max:20']]);

        $q = Article::where('is_published', true)->orderByDesc('views')->orderByDesc('id');

        if ($request->filled('category')) {
            $q->where('category', $request->query('category'));
        }

        if ($request->filled('q')) {
            // Every word must appear in the title, summary or body.
            foreach (preg_split('/\s+/', trim($request->query('q'))) as $word) {
                if (mb_strlen($word) < 2) {
                    continue;
                }
                $like = '%'.addcslashes($word, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('body', 'like', $like));
            }
        }

        $found = $q->limit((int) $request->query('limit', 10))->get(['id', 'title', 'slug', 'summary', 'category']);

        // Nothing contains those words: let the AI pick by meaning instead (only among published articles).
        $smart = false;
        if ($found->isEmpty() && $request->filled('q') && ! $request->filled('category')) {
            $found = $semantic->semantic($request->query('q'), 3)->map(fn ($a) => $a->only(['id', 'title', 'slug', 'summary', 'category']))->map(fn ($a) => (object) $a);
            $smart = $found->isNotEmpty();
        }

        // Remember searches (not browsing) so admins can see what people look for and do not find.
        $term = mb_strtolower(trim((string) $request->query('q')));
        if (mb_strlen($term) >= 3 && ! $request->filled('category')) {
            DB::table('kb_searches')->insert(['query' => mb_substr($term, 0, 100), 'results' => $found->count(), 'created_at' => now()]);
        }

        return response()->json(['smart' => $smart, 'articles' => $found->map(fn ($a) => is_array($a) || $a instanceof \stdClass ? (array) $a : $a->only(['id', 'title', 'slug', 'summary', 'category']))->values()]);
    }

    public function categories(): JsonResponse
    {
        $rows = Article::where('is_published', true)->whereNotNull('category')->selectRaw('category, count(*) as articles')->groupBy('category')->orderBy('category')->get();

        return response()->json(['categories' => $rows->map(fn ($r) => ['name' => $r->category, 'articles' => (int) $r->articles])]);
    }

    /**
     * "Was this helpful?" One vote per reader per article; voting again changes the answer.
     */
    public function vote(string $slug, Request $request): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        $article = Article::where('slug', $slug)->where('is_published', true)->firstOrFail();

        $key = hash('sha256', ($request->user('sanctum')?->id ? 'u'.$request->user('sanctum')->id : 'g'.$request->ip().'|'.$request->userAgent()).'|'.config('app.key'));

        DB::transaction(function () use ($article, $key, $data) {
            $vote = DB::table('article_votes')->where('article_id', $article->id)->where('voter_key', $key)->lockForUpdate()->first();
            if ($vote && (bool) $vote->helpful === (bool) $data['helpful']) {
                return;
            }
            if ($vote) {
                DB::table('article_votes')->where('id', $vote->id)->update(['helpful' => $data['helpful'], 'updated_at' => now()]);
            } else {
                DB::table('article_votes')->insert(['article_id' => $article->id, 'voter_key' => $key, 'helpful' => $data['helpful'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $article->forceFill([
                'helpful_count' => DB::table('article_votes')->where('article_id', $article->id)->where('helpful', true)->count(),
                'unhelpful_count' => DB::table('article_votes')->where('article_id', $article->id)->where('helpful', false)->count(),
            ])->save();
        });

        return response()->json(['message' => 'Thanks for your feedback!']);
    }

    /** Staff: what is read, what helps, and what people search for without finding an answer. */
    public function analytics(): JsonResponse
    {
        $articles = Article::where('is_published', true)->get();
        $rated = $articles->filter(fn ($a) => $a->helpful_count + $a->unhelpful_count >= 1);

        return response()->json([
            'top_viewed' => $articles->sortByDesc('views')->take(5)->values()->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'views' => $a->views]),
            'least_helpful' => $rated->filter(fn ($a) => $a->unhelpful_count > 0)->sortByDesc(fn ($a) => $a->unhelpful_count / ($a->helpful_count + $a->unhelpful_count))->take(5)->values()
                ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'helpful' => $a->helpful_count, 'unhelpful' => $a->unhelpful_count]),
            'missing_answers' => DB::table('kb_searches')->where('results', 0)->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('query, count(*) as times')->groupBy('query')->orderByDesc('times')->limit(10)->get()
                ->map(fn ($r) => ['query' => $r->query, 'times' => (int) $r->times]),
            'totals' => ['published' => $articles->count(), 'views' => $articles->sum('views'), 'helpful' => $articles->sum('helpful_count'), 'unhelpful' => $articles->sum('unhelpful_count')],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $article = Article::where('slug', $slug)->where('is_published', true)->firstOrFail();
        $article->increment('views');

        return response()->json(['article' => $this->present($article)]);
    }

    // ---- Staff management (lead/admin) ----

    public function manage(): JsonResponse
    {
        return response()->json([
            'articles' => Article::orderByDesc('updated_at')->get()->map(fn ($a) => $this->present($a, true)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $article = Article::create($data + ['slug' => $this->uniqueSlug($data['title']), 'author_id' => $request->user()->id]);

        return response()->json(['article' => $this->present($article, true)], 201);
    }

    public function update(Article $article, Request $request): JsonResponse
    {
        $article->update($this->validated($request));

        return response()->json(['article' => $this->present($article->fresh(), true)]);
    }

    public function destroy(Article $article): JsonResponse
    {
        $article->delete();

        return response()->json(['message' => 'Article deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:50000'],
            'is_published' => ['required', 'boolean'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')],
            'category' => ['nullable', 'string', 'max:60'],
        ]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'article', 180, '');
        $slug = $base;
        for ($i = 2; Article::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function present(Article $a, bool $staff = false): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'slug' => $a->slug,
            'summary' => $a->summary,
            'category' => $a->category,
            'body' => $a->body,
            'updated_at' => $a->updated_at?->toISOString(),
        ] + ($staff ? ['is_published' => $a->is_published, 'views' => $a->views, 'helpful_count' => $a->helpful_count, 'unhelpful_count' => $a->unhelpful_count, 'department_id' => $a->department_id] : []);
    }
}
