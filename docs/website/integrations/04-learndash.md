Connect LearnDash course and group enrollment to Jetonomy spaces - students get dedicated discussion areas automatically when they enroll, and lose access when they un-enroll.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

![Jetonomy Settings - Integrations tab showing the integration status table and the Auto-Create Spaces for Courses card](images/integrations-settings.png)

LearnDash is the lead LMS integration in this section. The other four LMS guides (Tutor, LifterLMS, Sensei, MasterStudy) work the same way and link back here for the screenshots and the shared Access Rules walkthrough.

## What You Will Learn

- How to gate a Jetonomy space by LearnDash course enrollment
- How to gate a space by LearnDash group membership
- How to auto-create spaces when new courses are published
- How to sync existing students into a space
- What happens when a student un-enrolls

## How Detection Works

Jetonomy Pro detects LearnDash automatically when both plugins are active. A **LearnDash Course** option appears in the Access Rules rule type dropdown - no setup needed. Compatible with LearnDash 4.x and 5.x.

## Gating a Space by Course Enrollment

![The Access Rules course picker with a searchable dropdown autocompleting course names as you type](images/course-search-autocomplete.png)

1. Go to **Jetonomy → Spaces** → open the space → **Access Rules** tab.
2. Select **LearnDash Course** from the rule type dropdown.
3. Start typing the course name - a searchable dropdown shows all published LearnDash courses.
4. Select the course, set **Grants** to **Participate** and **Space Role** to **Member**.
5. Click **Add Rule**.

The rule appears in the table showing the course name and a **Sync Members** button. For what the **Grants** and **Space Role** fields mean, see [Grants and Space Role](01-memberpress.md#grants-and-space-role).

## Gating a Space by LearnDash Group

LearnDash groups also appear in the searchable dropdown. This is ideal for cohort-based learning - one group, one space.

1. Select **LearnDash Course** from the rule type dropdown.
2. Type the group name - groups show as "Group Name (LD Group)" in the results.
3. Select the group, set Grants and Space Role, and click **Add Rule**.

All members of the LearnDash group - including group leaders - gain access. When a user is removed from the group, they lose space access.

## Syncing Existing Students

If students are already enrolled before the rule was created, click the **Sync Members** button. This pulls in all currently enrolled users. A notification shows how many were synced.

New enrollments and removals are handled automatically after the rule is created.

## Auto-Create Spaces for New Courses

The **Auto-Create Spaces for Courses** card and the integration status table both live on the Integrations tab shown in the screenshot at the top of this guide (**Jetonomy → Settings → Integrations**).

1. Go to **Jetonomy → Settings → Integrations**.
2. Enable the **LearnDash** toggle under Auto-Create Spaces for Courses.
3. Choose the default space type (Q&A, Forum, or Feed).
4. Click **Save Settings**.

When you publish a new course in LearnDash, a private discussion space is automatically created with:
- The course title as the space name
- A membership access rule linking the course to the space
- The course author assigned as space admin

## Enrollment and Un-enrollment Events

| LearnDash Event | Jetonomy Action |
|---|---|
| Student enrolls in course | Added to linked spaces as Member |
| Student completes course | Access retained |
| Student un-enrolls or is removed | Removed from linked spaces |
| User added to group | Added to linked spaces as Member |
| User removed from group | Removed from linked spaces |

Content (posts and replies) remains in the space - only access is revoked.

## Typical Setup for a Course Community

- One **Private** space per course, gated to that course's enrollment
- One **Public** space for general Q&A open to all students
- One **Hidden** space per group/cohort, gated to a LearnDash Group

## Troubleshooting

**LearnDash Course does not appear in the rule type dropdown** - Confirm Jetonomy Pro and LearnDash are both active. Check **Jetonomy → Settings → Integrations** to see the LearnDash status.

**Students still have access after un-enrolling** - Confirm the un-enrollment uses LearnDash's standard course access management. Custom enrollment plugins that bypass the `learndash_update_course_access` hook will not trigger removal.

**Sync Members shows 0 synced** - The students may already be space members, or no users are enrolled in the selected course.

## What's Next?

Learn how to gate spaces using Restrict Content Pro subscriptions.

[Restrict Content Pro Integration →](05-rcp.md)
