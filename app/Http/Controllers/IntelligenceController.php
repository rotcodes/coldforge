<?php

namespace App\Http\Controllers;

use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\AiRequest;
use App\Models\MailboxSetting;
use App\Services\AI\ColdForgeIntelligence;
use App\Services\AI\IntelligenceActionService;
use App\Services\AI\StructuredAiClient;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IntelligenceController extends Controller
{
    public function index(Request $request, StructuredAiClient $ai)
    {
        $conversations = AiConversation::where('user_id', $request->user()->id)->latest('updated_at')->limit(30)->get(['id', 'title', 'mode', 'updated_at']);
        $conversation = $request->integer('conversation')
            ? AiConversation::where('user_id', $request->user()->id)->findOrFail($request->integer('conversation'))
            : $conversations->first();

        return Inertia::render('Intelligence/Index', [
            'conversations' => $conversations,
            'conversation' => $conversation ? [
                'id' => $conversation->id, 'title' => $conversation->title, 'mode' => $conversation->mode,
                'messages' => $conversation->messages()->oldest('id')->get(['id', 'role', 'content', 'tool_calls', 'created_at']),
                'actions' => $conversation->actions()->latest('id')->limit(30)->get(['id', 'action', 'risk', 'status', 'summary', 'payload', 'result', 'error', 'created_at'])->reverse()->values(),
            ] : null,
            'available' => MailboxSetting::current()->ai_processing_enabled && $ai->available(),
            'usage' => ['requests' => AiRequest::whereDate('created_at', today())->count(), 'tokens' => (int) AiRequest::whereDate('created_at', today())->sum(\DB::raw('input_tokens + output_tokens'))],
        ]);
    }

    public function create(Request $request)
    {
        $conversation = AiConversation::create(['user_id' => $request->user()->id, 'mode' => 'read_only']);

        return redirect('/intelligence?conversation='.$conversation->id);
    }

    public function mode(Request $request, AiConversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
        $data = $request->validate(['mode' => ['required', 'in:read_only,approval,operator']]);
        $conversation->update($data);

        return back()->with('success', 'Intelligence permission mode updated.');
    }

    public function action(Request $request, AiAction $action, IntelligenceActionService $actions)
    {
        abort_unless($action->user_id === $request->user()->id, 404);
        $data = $request->validate(['decision' => ['required', 'in:confirm,reject']]);
        try {
            $result = $data['decision'] === 'confirm' ? $actions->execute($action, true) : $actions->reject($action);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result->only(['id', 'action', 'risk', 'status', 'summary', 'payload', 'result', 'error']));
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
