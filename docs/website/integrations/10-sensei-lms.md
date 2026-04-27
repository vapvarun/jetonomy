Connect Sensei LMS course enrollment to Jetonomy spaces — students get a dedicated discussion area when enrolled, and lose access when withdrawn.

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to gate a space by Sensei LMS course enrollment
- How to auto-create spaces when new courses are published
- How to sync existing students into a space

## How Detection Works

Jetonomy Pro detects Sensei LMS automatically when both plugins are active. A **Sensei Course** option appears in the Access Rules rule type dropdown.

![Integrations settings showing active LMS plugins](../images/integrations-settings.png)

## Linking a Course to a Space

1. Go to **Jetonomy → Spaces** → open the space → **Access Rules** tab.
2. Select **Sensei Course** from the rule type dropdown.
3. Start typing the course name — a searchable dropdown shows all published Sensei courses.
4. Select the course, set Grants to **Participate** and Space Role to **Member**.
5. Click **Add Rule**.

## Syncing Existing Students

Click the **Sync Members** button next to any rule to pull in all currently enrolled learners. New enrollments and withdrawals sync automatically.

## Auto-Create Spaces for New Courses

1. Go to **Jetonomy → Settings → Integrations**.
2. Enable the **Sensei LMS** toggle under Auto-Create Spaces for Courses.
3. Choose the default space type and save.

When you publish a new Sensei course, a private space is created with the course name, an access rule, and the course author (teacher) as space admin.

## Enrollment and Un-enrollment Events

Sensei uses a single enrollment status change event that handles both enrollment and withdrawal:

| Sensei LMS Event | Jetonomy Action |
|---|---|
| Learner enrolled in course | Added to linked space as Member |
| Learner completes course | Access retained |
| Learner withdrawn from course | Removed from linked space |
| Learner manually enrolled by admin | Added to linked space as Member |
| Learner manually withdrawn by admin | Removed from linked space |

Content remains in the space — only access is revoked.

## WooCommerce Integration

Sensei LMS integrates with WooCommerce for paid courses. When a student purchases a course through WooCommerce and Sensei enrolls them, the Jetonomy space access rule triggers automatically — no additional WooCommerce adapter needed for course gating.

## Troubleshooting

**Sensei Course does not appear in dropdown** — Confirm Jetonomy Pro and Sensei LMS are both active. Check **Jetonomy → Settings → Integrations**.

**Students not losing access after withdrawal** — Ensure the withdrawal uses Sensei's standard enrollment management. Set the space to **Private** to fully restrict access.

## What's Next?

[MasterStudy LMS Integration →](11-masterstudy-lms.md)
