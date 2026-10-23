# 0038. Consent is unticked every time, and the gate explains itself inline

Status: accepted (client findings 6, 22, 26)

## Decision

- Both boxes on Before you pay arrive unticked, always. The earlier carry-over for
  accounts with an age-shield verification is removed: consent must be an affirmative
  act, and the first box now also agrees to the terms. `autocomplete="off"` plus a
  `pageshow` handler stop the browser restoring ticks on Back or Forward.
- The native `required` bubble is gone (`novalidate`). "Tick both boxes to continue"
  sits under the button and the unticked boxes are outlined. Two routes show it:
  script blocks the submit and shows it at once; without script, checkout's
  `require_consent()` sends the player back to Before you pay with
  `saw_consent_missing=1` and the page prints it server-side. The gate itself was
  always the server's, so dropping `required` does not open it.
- A form submission without both ticks also clears any consent from an earlier
  visit (`saw_consent_check` marks a form post), so it cannot be skipped with no script.
- Back goes to the competition in the basket (the section's own URL), the list when
  the basket is empty. It does not depend on the referrer.
