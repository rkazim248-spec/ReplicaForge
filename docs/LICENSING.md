# Licensing

ReplicaForge has a licensing architecture and no licensing service. This document
explains the boundary, because the boundary is the deliverable.

**Nothing in this plugin contacts a licensing server, verifies a license key, or
simulates an activation.** The only implementation that ships reads a value an
administrator set in the database. Connecting a real service later means writing
one class behind a filter; it does not mean rewriting the plan system.

---

## 1. Why an interface with no implementation

The brief asks for a provider-independent licensing architecture that works
completely locally. Those two requirements have one honest answer: ship the
abstraction, ship a local provider, and ship **no** remote one.

An interface with no implementation is the strongest available statement that no
payment or activation flow exists. There is no code path that could take a card
or phone a key, because there is no code to do either.

`BillingProvider` goes further and has no method with a side effect. Every method
returns a *fact about the world*; none changes it. There is no `charge()`, no
`create_customer()`, no `cancel_immediately()`. A billing provider reads. A
separate, later, separately reviewed module would write. Keeping the write verbs
out of the interface is what makes it safe to ship the interface now.

---

## 2. The interfaces

```php
interface License_Provider_Contract {
    public function id();          // unique provider identifier
    public function label();       // display name
    public function is_remote();   // does it need an outbound connection?
    public function state();       // License_State, never a plan id, never a throw
}
```

```php
interface Billing_Provider_Contract {
    public function id();
    public function label();
    public function is_configured();
    public function is_remote();
    public function diagnostics();
    public function sellable_plan_ids();
}
```

Two shapes in `License_Provider_Contract` are deliberate and are the security
properties of the whole system:

**A provider returns a `License_State`, never a plan id.** "The provider said the
license is active" and "the user is on the Pro plan" are different claims, and
only the first is a provider's to make. The plan follows from the state plus a
locally configured mapping, so a confused or compromised provider can hand out a
state and nothing more.

**There is no `activate()`.** §11 forbids fake activation, and the surest way not
to build it is to leave the method out. A real licensing server requires an
outbound signed request, which is not expressible as "read a value out of the
database" — so a future provider will be a different interface, added when there
is something real to talk to.

---

## 3. The eight states

| State | Grants | Temporary | A problem the user must be told about |
|---|---|---|---|
| `active` | ✓ | | |
| `trial` | ✓ | | |
| `grace_period` | ✓ | ✓ | |
| `expired` | | | ✓ |
| `invalid` | | | ✓ |
| `revoked` | | | ✓ |
| `inactive` | | | |
| `unknown` | | ✓ | |

Three decisions in that table are worth defending.

**`grace_period` grants; `expired` does not.** A grace window exists precisely so
that a site does not stop generating the moment a payment bounces — the whole
point is to give the user time to fix it. If `expired` also granted, the grace
period would be meaningless.

**`inactive` and `unknown` are not problems.** Nobody bought anything, and an
unreachable provider is the provider's problem. `expired`, `invalid`, and
`revoked` are statements about a license that existed.

**`unknown` is not permission.** A provider that cannot answer returns `unknown`,
and `unknown` grants nothing. This is the direction that matters: a licensing
integration that fails closed must not silently grant paid features.

An unrecognised state name becomes `unknown` rather than being kept. Keeping it
would mean a provider typo silently producing a state that grants nothing and
appears in no list, and the resulting support question has no answer.

### Expiry is evaluated on read

```php
$state = License_State::make( License_State::ACTIVE, '', time() - 5 );
$state->effective_name();   // 'expired'
$state->grants();           // false
```

A state built a second ago can become false without anything changing it, so the
demotion happens in a method rather than in a setter. Code that checked at
construction would keep honouring a license that lapsed while the page sat open.

`grants()` is time-aware for the same reason: a no-argument method that answered
from the state as declared would return `true` for a license that expired an hour
ago, which is the most dangerous single method in the file.

---

## 4. Resolution

```
1. License_Provider_Contract::state()  →  is this site entitled to a paid plan?
2. configured plan id                   →  which one
3. per-user trial                       →  upgrades the free plan only
```

**An inactive license means the free plan, not the configured plan.** If the
configured plan were honoured regardless, the license would be decorative: an
administrator who sets "Pro" would get Pro whether or not any license exists, and
the whole feature would be a label on a settings screen. Making the license the
gate is what makes it mean something, and it is also the safe direction.

The consequence is worth stating plainly: **a site whose license is `inactive`
runs on the free plan even if the configured plan says `pro`.** To run a paid
plan, the local record must be set to a granting state. That is a deliberate
consequence, and it is why `License_Manager::diagnostics()` reports both
`configured_plan` and `licensed_plan` — so the difference is visible rather than
something a user has to guess about.

**A trial only ever upgrades the free plan.** A site already on a paid plan has no
reason to be inside a trial, and applying one is either a downgrade or a no-op.
Restricting the rule to the free plan removes both cases instead of needing a rank
comparison to detect them.

---

## 5. The local provider

`Local_License_Provider` is the only implementation. It:

- reads a four-field record from `replicaforge_license_local`;
- performs no network request, and contains no code that could;
- has no key to enter, no signature to verify, and no activation step;
- records `verification: none_local_record`, and says so in the UI.

An administrator either sets the plan for this site — a local configuration
decision, not a purchase — or leaves it alone and the site runs on the free plan.

**The record carries no plan id.** It may say `active`; what plan that means is
decided by `License_Manager` against the local plan set. That keeps the same
separation a remote provider has to respect, so swapping in a remote provider
changes nothing downstream.

`License_Manager::diagnostics()` reports `verified: false` for a local record.
An administrator can always tell the difference between "a licensing server said
this" and "somebody typed this".

---

## 6. Connecting a real provider

```php
add_filter( 'replicaforge_license_provider', function () {
    return new My_Licensing_Provider();
} );
```

The provider must return a `License_State` and must not throw. A provider that
throws is caught and treated as `unknown`, which resolves to the free plan: a
licensing outage must not take generation down with it, and the failure direction
is "less access", not "no access".

Downstream behaviour — plans, entitlements, limits, the meter, the refusals —
requires no changes. That is the point of the abstraction.

Two things to get right when writing one:

- **Cache the state.** `state()` is called several times per dashboard render.
- **Never return a plan id.** Return a state. The interface has no method that
  could carry one, and adding one would break the property the plan system rests on.

---

## 7. Trials

Disabled by default. A development installation should not hand out paid
entitlements on its own, and a trial that starts without being asked for is a
trial nobody agreed to.

| Setting | Default | Notes |
|---|---|---|
| `enabled` | `false` | |
| `days` | `14` | Clamped to 0–365. Zero with trials on is refused. |
| `plan` | `pro` | Validated against the plan set. |
| `allow_reentry` | `false` | |

Trial state is **per user**, in user meta (`trial_started_at`, `trial_expires_at`,
`trial_plan`), while licensing is per site. That split is deliberate: a license is
for a site, and a trial is an account benefit.

A trial is started by the user, on the server, and only when the configuration
says one is available. There is no automatic start on activation.

**Cancelling a trial does not reset it.** The record is kept with an expiry in the
past, so the trial cannot be restarted by cancelling it. A "cancel" that re-enables
the trial is not a cancel.

If the configured trial plan does not exist — because a plan was removed after a
trial was configured against it — no trial starts. A trial pointing at a plan that
no longer exists must not grant anything.

---

## 8. What the product says

`Onboarding::product_notice()` is shown on the plan screen, and it says:

- whether a billing provider is connected;
- that with none, **no payment is taken and no purchase is simulated**;
- that plans are a local configuration an administrator can change.

`Local_License_Provider::diagnostics()` adds: no licensing server is contacted,
ReplicaForge works fully offline; no license key is validated and no activation is
simulated; the plan applied to this site is configured in ReplicaForge settings.

This is stated **in the product**, not only in the documentation. The brief is
emphatic that no fake payment or license functionality may be presented as real,
and the only reliable way to honour that is to say it where a user will read it
before they go looking for a checkout button.
