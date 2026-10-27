<?php

namespace App\Mcp\Tools;

use App\Models\Feedback;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Feedback despre cemainou (un bug, ceva greu de folosit, o idee), ca din butonul „Feedback" de pe web: ajunge la admini, în clopoțel. */
#[Name('send_feedback')]
#[Description('Sends feedback about cemainou itself to its admins (not about a project): a bug, something hard to use, an idea - as the Feedback button on the web does. The person is told in the bell when it is resolved. Only when the person asks; show them the text first.')]
class SendFeedbackTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['text' => 'required|string|min:5|max:5000', 'page' => 'nullable|string|max:2000']);
        $user = $request->user();
        $this->record($request);
        if (Gate::forUser($user)->denies('create', Feedback::class)) {
            return Response::error('Your account cannot send feedback.');
        }
        $feedback = Feedback::submit($user, $data['text'], ['url' => $data['page'] ?? null], [], 'agent: '.(string) request()->userAgent());

        return Response::text("Feedback #{$feedback->id} sent to the cemainou admins. The person will see in the bell when it is resolved.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->max(5000)->required()->description('What happened or what would help, in the person\'s words; for a bug, what they did and what they saw.'),
            'page' => $schema->string()->description('Optional: the cemainou address it is about.'),
        ];
    }
}
