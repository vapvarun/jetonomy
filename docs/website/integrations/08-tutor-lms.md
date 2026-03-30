Connect Tutor LMS course enrollment to Jetonomy spaces — students get a dedicated discussion area automatically when they enroll, and lose access when they cancel or are removed.

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to gate a Jetonomy space by Tutor LMS course enrollment
- How open courses map to public spaces and paid courses to private spaces
- What happens when a student un-enrolls or cancels

## How Detection Works

Jetonomy Pro detects Tutor LMS automatically when both plugins are active. The Tutor adapter registers with the Adapter Registry and adds a new Rule Type to the Access Rules tab: **Tutor Course**.

## Gating a Space by Course Enrollment

1. Go to **Jetonomy → Spaces** and open (or create) the discussion space for your course.
2. Open the **Access Rules** tab.
3. Click **Add Rule** → set Rule Type to **Membership**.
4. In the Value field, enter the course level ID (format: `tutor_course_123` where 123 is the course post ID).
5. Set Grants to **Participate** and Space Role to **Member**.
6. Save the rule.

Students who enroll in the selected course are added to the space automatically. Students who cancel or are removed from the course lose access immediately.

> **Tip:** Create one Jetonomy space per course and name it to match — for example "Photography Fundamentals — Discussion". Members immediately understand where they are.

## Open vs Paid Courses

| Tutor Course Type | Recommended Space Setup |
|---|---|
| Public course (`_tutor_is_public_course` = yes) | **Public** space with **Open** join policy — anyone can read and participate |
| Free course (requires enrollment) | **Public** space — enrollment sync adds students as Members |
| Paid course (WooCommerce/EDD) | **Private** space — only enrolled students can see content |

## Enrollment and Un-enrollment Events

| Tutor LMS Event | Jetonomy Action |
|---|---|
| Student enrolls in course | Added to linked spaces as Member |
| Student completes course | Access retained — they keep their discussion space |
| Student enrollment cancelled | Removed from linked spaces |
| Student enrollment deleted | Removed from linked spaces |

> **Note:** Course completion does not revoke access. Students keep access to their discussion space after finishing the course. Their posts and replies remain visible to other space members.

## Typical Setup for a Course Community

A common setup for Tutor LMS course communities:

- One **Private** space per paid course, gated to that course's enrollment
- One **Public** space per free course for open discussion
- One **Public** space for general Q&A open to all students

This keeps paid course discussions exclusive while giving all students a shared community area.

## Troubleshooting

**Tutor Course does not appear in access rules** — Confirm Jetonomy Pro is active and Tutor LMS is active. The adapter auto-detects Tutor when the `TUTOR_VERSION` constant exists.

**Students still have access after cancellation** — Confirm the cancellation uses Tutor's standard enrollment management. Custom enrollment plugins that bypass the `tutor_after_enrollment_cancelled` hook will not trigger Jetonomy's removal logic.

**Student was removed but still sees the space** — If the space visibility is set to **Public**, non-members can still read content. Set the space to **Private** to fully restrict access to enrolled students only.

## What's Next?

Learn how to connect Jetonomy with your WordPress theme for a seamless look.

[Theme Compatibility →](07-theme-compatibility.md)
