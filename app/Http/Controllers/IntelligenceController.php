<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiRequest;
use App\Models\MailboxSetting;
use App\Services\AI\ColdForgeIntelligence;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IntelligenceController extends Controller
{
    public function index(Request $request)
    {
        $conversations = AiConversation::where('user_id', $request->user()->id)->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at']);
        $conversation = $request->integer('conversation')
            ? AiConversation::where('user_id', $request->user()->id)->findOrFail($request->integer('conversation'))
            : $conversations->first();

        return Inertia::render('Intelligence/Index', [
            'conversations' => $conversations,
            'conversation' => $conversation ? ['id' => $conversation->id, 'title' => $conversation->title, 'messages' => $conversation->messages()->oldest('id')->get(['id', 'role', 'content', 'tool_calls', 'created_at'])] : null,
            'available' => MailboxSetting::current()->ai_processing_enabled && filled(config('services.groq.api_key')),
            'usage' => ['requests' => AiRequest::whereDate('created_at', today())->count(), 'tokens' => (int) AiRequest::whereDate('created_at', today())->sum(\DB::raw('input_tokens + output_tokens'))],
        ]);
    }

    public function create(Request $request)
    {
        $conversation = AiConversation::create(['user_id' => $request->user()->id]);

        return redirect('/intelligence?conversation='.$conversation->id);
    }

    public function chat(Request $request, AiConversation $conversation, ColdForgeIntelligence $intelligence)
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);
        try {
            $result = $intelligence->respond($conversation, $data['message']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function destroy(Request $request, AiConversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
        $conversation->delete();

        return redirect('/intelligence')->with('success', 'Intelligence conversation deleted.');
    }
}
