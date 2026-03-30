Connect LifterLMS course and membership enrollment to Jetonomy spaces — students get a dedicated discussion area when they enroll, and lose access when removed.

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to gate a space by LifterLMS course or membership enrollment
- How to auto-create spaces when new courses are published
- How to sync existing students into a space

## How Detection Works

Jetonomy Pro detects LifterLMS automatically when both plugins are active. A **LifterLMS Course** option appears in the Access Rules rule type dropdown.

## Linking a Course to a Space

1. Go to **Jetonomy → Spaces** → open the space → **Access Rules** tab.
2. Select **LifterLMS Course** from the rule type dropdown.
3. Start typing the course name — a searchable dropdown shows all published courses and memberships.
4. Select the course, set Grants to **Participate** and Space Role to **Member**.
5. Click **Add Rule**.

LifterLMS memberships also appear in the search — select a membership to gate a space by membership level instead of individual course enrollment.

## Syncing Existing Students

Click the **Sync Members** button next to any rule to pull in all currently enrolled students. New enrollments and removals sync automatically.

## Auto-Create Spaces for New Courses

1. Go to **Jetonomy → Settings → Integrations**.
2. Enable the **LifterLMS** toggle under Auto-Create Spaces for Courses.
3. Choose the default space type and save.

When you publish a new LifterLMS course, a private space is created with the course name, an access rule, and the course author as space admin.

## Enrollment and Un-enrollment Events

| LifterLMS Event | Jetonomy Action |
|---|---|
| Student enrolls in course | Added to linked space as Member |
| Student added to membership | Added to linked space as Member |
| Student completes course | Access retained |
| Student removed from course | Removed from linked space |
| Student removed from membership | Removed from linked space |
| Enrollment permanently deleted | Removed from linked space |

Content remains in the space — only access is revoked.

## Memberships and Courses

LifterLMS memberships can auto-enroll students in multiple courses. When a student joins a membership:
- They gain access to spaces linked to the membership itself
- They also gain access to spaces linked to the auto-enrolled courses (via the course enrollment hooks)

## Troubleshooting

**LifterLMS Course does not appear in dropdown** — Confirm Jetonomy Pro and LifterLMS are both active. Check **Jetonomy → Settings → Integrations**.

**Membership students not getting access** — Ensure the access rule uses the membership level ID (shows as "Membership Name (LifterLMS Membership)" in the search). Course-level rules only apply to direct course enrollment.

## What's Next?

[Sensei LMS Integration →](10-sensei-lms.md)
