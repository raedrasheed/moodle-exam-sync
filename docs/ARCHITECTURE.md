# Architecture and operating boundaries

This alpha implements one source/target pair on Moodle 5.1. Source is authoritative for content, course membership and admission. Target is authoritative for quiz attempts and grades. Pair identifiers and the source LTI issuer are stable installation identities.

## Data flow

Content events queue revision-specific ad hoc jobs. A source job exports one native activity backup with user data disabled, prunes unrelated question bank entries and files, namespaces question/category stamps, removes question comments and local question plugin payloads, and adds an enrolled-user/group/override snapshot. Related question versions are retained within selected bank entries. A random slot carries its candidate pool, not one preselected question.

The manifest contains UUID, revision, Moodle branch, size, SHA-256, question types, slot count and course metadata. Signed chunks are assembled and hashed at the target before a restore job is queued. An isolated hidden revision is restored with the native restore API. The target reuses one published LTI resource UUID as its context moves to the new quiz. Old activities become hidden; the access rule denies unmapped activities bearing the managed idnumber.

The target sends an acknowledgement. The source queues publication under the publishing user's account, creates or updates the native external-tool activity, suppresses the authoring quiz grade item, and marks the revision ready. Initial grade category and weighting are copied to the LTI item. Later manual LTI visibility/category choices remain with the teacher.

## Concurrency and failure

| Mechanism | Purpose |
| --- | --- |
| Resource UUID and revision | Stable identity; reject stale content |
| Unique operation keys | Repeated prepare/commit/acknowledgement do not duplicate resources |
| Resource lock | Serialize target restore and admission; serialize source network jobs |
| Short source map lock | Read eligibility and freeze against source revision updates |
| Dedicated course restore lock | Keep restores for a shared target course serialized |
| Grade sequence per exam/user | Prevent older deliveries overwriting newer grades |
| Runtime sequence per exam | Apply membership/override snapshots in order |
| Moodle task retries | Retry network and processing failures with core backoff |

Source authorization deliberately does not wait on its outbound-network resource lock. This avoids a target admission request and a source upload holding opposite ends of the same two-site lock cycle. Target holds its admission lock through the core attempt creation event. Source and target freeze conservatively at admission, including an accepted admission later abandoned before attempt creation. An administrator can release only an unused freeze via the recovery CLI after target verification.

Once real attempts exist, content replacement is refused. Existing attempts are not deleted, reset or copied back to source. A content error blocks new admissions but the access rule does not block continuing an existing attempt. Membership revocation still follows native Moodle access semantics. Loss of source connectivity prevents starting a new attempt.

## Authentication and grade transport

Native LTI 1.3 handles browser launch, JWT validation and account binding. Provisioning uses `auth_lti` with the exact same issuer/subject pair as subsequent launches. No password copying or email-based account guessing occurs. Native periodic LTI grade/member synchronization is disabled on each managed target resource. Event-driven signed requests deliver grades directly to the canonical native LTI grade item; this is not an AGS service implementation.

Each API message has a sender ID, timestamp, random nonce and HMAC-SHA256 signature over sender/time/nonce/body hash. The validity window is 120 seconds. A unique database nonce key rejects concurrent replay. HTTPS verification is enabled, redirects are not followed and Moodle's cURL security policy applies. HTTP requires explicit isolated-lab configuration. The configured peers are trusted administrators; never accept this shared secret from a third-party course author.

Errors in HTTP responses do not include SQL, credentials or payloads. The course status page and Moodle task logs are for authorized staff. Normal completion removes backup transfer directories on each side. Failed or superseded transfers can remain for diagnosis; remove them only after confirming no retry needs them. Runtime payloads contain personal data and are handled by the privacy provider.

## Privacy and deletion

Bridge-owned identity mappings, membership indexes, author IDs, grade delivery sequences and cached membership snapshots are declared to Moodle's Privacy API. A privacy erasure removes affected cached transfer data and pauses that resource as `privacyblocked`, so pending jobs cannot recreate erased data. Existing native attempts/grades/accounts are managed by Moodle's quiz, grade, enrolment and authentication privacy providers. Erasure must be coordinated on both independent sites. A privacy-paused exam is not resumed through the abandoned-admission recovery command.

## Boundaries to verify before production

- Moodle 5.1 only. Both sites need compatible question types/behaviours and access-rule plugins. Native built-in question backup is used; third-party plugins need a real acceptance run. Local question backup plugin data and question comments are intentionally omitted.
- Browser LTI registration, cookie behavior, real attempt submission, network outages during attempts and load capacity require acceptance on the actual paired installation. PHPUnit exercises the database/native APIs and simulated receiving paths, not a browser SSO flow.
- No offline attempts. No retroactive migration of a quiz that already contains real source attempts. After admission, publish changed content as a new quiz.
- Course completion rules, calculated gradebook formulas, and other activities' restrictions referring to the source authoring quiz are not rewritten. Repoint these to the canonical LTI activity/item as appropriate before testing learners.
- Default package limit 128 MiB; chunks 1 MiB; signed requests at most 2 MiB. Runtime membership snapshots must fit the request limit. Very large cohorts need batching before adoption.
- New admissions rely on an online source. Native Moodle access checks still govern continuing attempts; withdrawing enrolment can revoke access.
- The scheduled GitHub workflow performs syntax/XML checks and packaging. Functional Moodle PHPUnit results are documented separately; do not describe packaging CI as a browser end-to-end test.

## Primary implementation references

- [Moodle 5.1 LTI pairing](https://docs.moodle.org/501/en/LTI_Moodle_to_Moodle)
- [Ad hoc task API](https://moodledev.io/docs/5.1/apis/subsystems/task/adhoc)
- [Backup API](https://moodledev.io/docs/5.1/apis/subsystems/backup)
- [Privacy API](https://moodledev.io/docs/5.1/apis/subsystems/privacy)
- [Moodle v5.1.3 source](https://github.com/moodle/moodle/tree/v5.1.3)
