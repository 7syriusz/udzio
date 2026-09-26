<?php

namespace App\Http\Controllers\Account;

use App\Domain\Identity\Actions\AddContact;
use App\Domain\Identity\Actions\RemoveContact;
use App\Domain\Identity\Actions\RequestContactVerification;
use App\Domain\Identity\Actions\VerifyContact;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Exceptions\ContactVerificationFailed;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Contacts of the account holder's own PERSON. Someone else's contact answers 404 (audited denial). */
class ContactController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.contacts', ['contacts' => $this->person($request)->contacts()->active()->orderBy('id')->get()]);
    }

    public function store(Request $request, AddContact $add): RedirectResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::enum(ContactChannel::class)], 'value' => ['required', 'string', 'max:254']]);
        $add->handle($this->person($request), ContactChannel::from($data['channel']), $data['value']);

        return back()->with('status', 'Dodano kontakt.');
    }

    public function destroy(Request $request, Contact $contact, RemoveContact $remove): RedirectResponse
    {
        $remove->handle($this->own($request, $contact, 'contact.remove'), 'account holder removed contact');

        return back()->with('status', 'Usunięto kontakt.');
    }

    public function sendVerification(Request $request, Contact $contact, RequestContactVerification $send): RedirectResponse
    {
        $send->handle($this->own($request, $contact, 'contact.verify'));

        return back()->with('status', 'Wysłaliśmy kod.');
    }

    public function verify(Request $request, Contact $contact, VerifyContact $verify): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        try {
            $verify->handle($this->own($request, $contact, 'contact.verify'), $data['code']);
        } catch (ContactVerificationFailed) {
            throw ValidationException::withMessages(['code' => 'Kod jest nieprawidłowy albo wygasł.']);
        }

        return back()->with('status', 'Kontakt potwierdzony.');
    }

    private function person(Request $request): Person
    {
        return $request->user()->person ?? abort(404);
    }

    private function own(Request $request, Contact $contact, string $ability): Contact
    {
        if ($contact->person_id !== $this->person($request)->id || $contact->removed_at !== null) {
            throw (new AccessDenied('contact', $contact->public_id, null, $ability))->hideAsNotFound();
        }

        return $contact;
    }
}
