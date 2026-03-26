Connect LearnDash course and group enrollment to Jetonomy spaces — so students get dedicated discussion areas automatically when they enroll, and lose access when they un-enroll.

![Jetonomy admin settings for LearnDash integration configuration](../images/admin-settings.png)

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to gate a Jetonomy space by LearnDash course enrollment
- How to gate a space by LearnDash group membership
- How instructors moderate their course discussion spaces
- What happens when a student un-enrolls

## How Detection Works

Jetonomy Pro detects LearnDash automatically when both plugins are active. The LearnDash adapter registers with the Adapter Registry and adds two new Rule Types to the Access Rules tab: **LearnDash Course** and **LearnDash Group**.

## Gating a Space by Course Enrollment

1. Go to **Jetonomy → Spaces** and open the discussion space for your course.
2. Open the **Access Rules** tab.
3. Click **Add Rule** → set Rule Type to **LearnDash Course**.
4. Select the course from the dropdown.
5. Set the action to **Grant**.
6. Save the space.

Students who enroll in the selected course are added to the space automatically. Students who complete or un-enroll from the course are removed.

> **Tip:** Create one Jetonomy space per course and name it to match — for example "Photography Fundamentals — Discussion". Members immediately understand where they are.

## Gating a Space by LearnDash Group

1. Add a rule with Rule Type **LearnDash Group**.
2. Select the group from the dropdown.
3. Set the action to **Grant** and save.

Group-gated spaces work well for cohort-based or team learning scenarios. All members of the LearnDash group — including group leaders — gain access.

## Instructor Moderation

When a WordPress user is assigned as a **Course Instructor** or **Group Leader** in LearnDash, Jetonomy Pro automatically assigns them the **Moderator** role in the linked Jetonomy space. They can approve, trash, or spam posts within their space, but cannot access the global Jetonomy moderation queue.

> **Note:** Instructor role mapping requires the user to be assigned as instructor in LearnDash before the space access rule is created. If you add the instructor after, re-save the Access Rule to trigger the role sync.

## Enrollment and Un-enrollment Events

| LearnDash Event | Jetonomy Action |
|---|---|
| Student enrolls in course | Added to linked spaces as Member |
| Student completes course | Access retained (configurable) |
| Student un-enrolls or is removed | Removed from linked spaces |
| Group leader assigned | Added to linked spaces as Moderator |
| Group leader removed | Demoted to Member or removed |

> **Note:** By default, course completion does not revoke access. Students keep access to their discussion space after finishing the course. You can change this by adding a second Revoke rule on the same course — or by using WooCommerce Subscriptions with a subscription-per-course model.

## Typical Setup for a Course Community

A common setup for course-based communities:

- One **Private** space per course, gated to that course's enrollment
- One **Public** space for general Q&A open to all students
- One **Hidden** space per instructor cohort, gated to a LearnDash Group

This keeps course discussions focused while giving students a shared general community area.

## Troubleshooting

**LearnDash Course/Group does not appear in Rule Type dropdown** — Confirm Jetonomy Pro is active and LearnDash is active. Check **Jetonomy → Extensions** to see the LearnDash integration status.

**Instructor not getting moderator role** — Ensure the WordPress user is assigned as Course Instructor in LearnDash's course settings, not just as a WordPress Editor or Administrator. Re-save the access rule after assigning the instructor role.

**Students still have access after un-enrolling** — Confirm the LearnDash un-enrollment uses the standard `learndash_user_course_access_removed` action. Custom enrollment plugins that bypass this action will not trigger Jetonomy's removal logic.

## What's Next?

Learn how to gate spaces using Restrict Content Pro subscriptions.

[Restrict Content Pro Integration →](05-rcp.md)
