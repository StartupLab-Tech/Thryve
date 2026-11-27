<?php

namespace App\Models;

use App\Plugins\PluginRegistry;
use Database\Factories\PluginActionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of an outside app. Reads are recorded once they finish. A change starts as `pending` with its arguments kept
 * encrypted, and only runs after the person approves it; the arguments are cleared as soon as it is decided.
 */
#[Hidden(['arguments', 'arguments_hash'])]
class PluginAction extends Model
{
    /** @use HasFactory<PluginActionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['arguments' => 'encrypted:array', 'expires_at' => 'datetime', 'decided_at' => 'datetime', 'executed_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Changes still waiting that ran out of time become `expired` and lose their arguments. */
    public static function expireStale(): void
    {
        self::where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired', 'arguments' => null, 'arguments_hash' => null]);
    }

    /**
     * What the browser may know about it. The exact arguments are included only when the owner is shown a change that waits for
     * their approval (they have to see what they are approving); they contain no token or ticket.
     *
     * @return array<string, mixed>
     */
    public function present(bool $withArguments = false): array
    {
        return ['id' => $this->id, 'app' => PluginRegistry::find($this->app_slug)?->name() ?? $this->app_slug, 'tool' => $this->tool, 'summary' => $this->summary, 'status' => $this->status, 'result_summary' => $this->result_summary,
            'expires_at' => $this->expires_at?->toIso8601String()] + ($withArguments ? ['arguments' => $this->arguments] : []);
    }
}
