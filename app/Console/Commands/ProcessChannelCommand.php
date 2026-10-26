<?php

namespace App\Console\Commands;

use App\Jobs\Ai\ProcessChannel;
use App\Models\AiRun;
use App\Models\Channel;
use App\Support\TelegramUsername;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:process {username} {--sync : Run every step in this process instead of queueing} {--from=extract : Step to start from}')]
#[Description('Run the AI pipeline (extract → projects → stats → summary → verify → index) for a channel')]
class ProcessChannelCommand extends Command
{
    public function handle(): int
    {
        $channel = Channel::where('username', TelegramUsername::normalize($this->argument('username')))->first();

        if ($channel === null) {
            $this->error('Unknown channel. Run buildr:scrape first.');

            return self::FAILURE;
        }

        $since = now();
        $step = (string) $this->option('from');

        if (! $this->option('sync')) {
            ProcessChannel::dispatch($channel->id, $step);
            $this->info('Queued on the "ai" queue.');

            return self::SUCCESS;
        }

        config(['queue.default' => 'sync']);
        ProcessChannel::dispatchSync($channel->id, $step);

        $runs = AiRun::where('created_at', '>=', $since)->get();
        $this->info(sprintf('Done. %d AI calls, %s in / %s out tokens, ~$%.4f.',
            $runs->count(), number_format($runs->sum('input_tokens')), number_format($runs->sum('output_tokens')), $runs->sum('cost_usd_est')));

        return self::SUCCESS;
    }
}
