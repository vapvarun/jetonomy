<?php
/**
 * Demo content library — curated data arrays for the model-community seeder.
 *
 * Pure data. No DB access, no WordPress side effects. The generator in
 * {@see Demo_Seeder} consumes these arrays to build the model community so the
 * "what content" decisions stay separate from the "how to insert it" mechanics.
 *
 * All content is hand-curated and on-brand: this dataset backs documentation
 * screenshots and the media kit, so it must read like a real community. No
 * lorem, no runtime AI.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Demo_Content — static curated data arrays for the model-community seeder.
 */
class Demo_Content {

	/**
	 * The 4-category → 20-space taxonomy map.
	 *
	 * Each category is a row: name, slug, description, and an ordered list of
	 * spaces. Each space carries title, slug, type (forum|qa|ideas|feed), a
	 * Lucide icon name, visibility, join_policy, and a one-line description.
	 *
	 * All spaces are public/open except "Insiders / Beta" (private/approval).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function taxonomy(): array {
		return array(
			array(
				'name'        => 'Start Here',
				'slug'        => 'start-here',
				'description' => 'New to the community? Begin here — orientation, guidelines, introductions, and the events calendar.',
				'spaces'      => array(
					array(
						'title'       => 'Announcements',
						'slug'        => 'announcements',
						'type'        => 'feed',
						'icon'        => 'megaphone',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Official news from the team — launches, downtime, and important changes.',
					),
					array(
						'title'       => 'Community Guidelines',
						'slug'        => 'community-guidelines',
						'type'        => 'forum',
						'icon'        => 'scale',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'How we treat each other here. Read before posting; ask if anything is unclear.',
					),
					array(
						'title'       => 'Introduce Yourself',
						'slug'        => 'introduce-yourself',
						'type'        => 'forum',
						'icon'        => 'hand',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'New here? Tell us who you are, what you build, and what brought you in.',
					),
					array(
						'title'       => 'Getting Started',
						'slug'        => 'getting-started',
						'type'        => 'qa',
						'icon'        => 'rocket',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'First-steps questions — setup, onboarding, and finding your way around.',
					),
					array(
						'title'       => 'Events & Office Hours',
						'slug'        => 'events-office-hours',
						'type'        => 'feed',
						'icon'        => 'calendar',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Upcoming AMAs, office hours, and community calls. Drop in and say hi.',
					),
				),
			),
			array(
				'name'        => 'Product & Engineering',
				'slug'        => 'product-engineering',
				'description' => 'Where the product gets built — feature talk, bug reports, the public roadmap, and integration help.',
				'spaces'      => array(
					array(
						'title'       => 'Feature Discussions',
						'slug'        => 'feature-discussions',
						'type'        => 'forum',
						'icon'        => 'message-square',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Open-ended conversations about how features should work and why.',
					),
					array(
						'title'       => 'Bug Reports',
						'slug'        => 'bug-reports',
						'type'        => 'qa',
						'icon'        => 'bug',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Found something broken? Report it here with steps to reproduce.',
					),
					array(
						'title'       => 'Feature Requests',
						'slug'        => 'feature-requests',
						'type'        => 'ideas',
						'icon'        => 'lightbulb',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Propose ideas, vote on what matters, and watch them move across the roadmap.',
					),
					array(
						'title'       => 'API & Integrations',
						'slug'        => 'api-integrations',
						'type'        => 'qa',
						'icon'        => 'plug',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'REST endpoints, webhooks, and connecting Jetonomy to your stack.',
					),
					array(
						'title'       => 'Release Notes',
						'slug'        => 'release-notes',
						'type'        => 'feed',
						'icon'        => 'tag',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => "What shipped, version by version. Subscribe so you don't miss a release.",
					),
				),
			),
			array(
				'name'        => 'Community',
				'slug'        => 'community',
				'description' => 'The town square — general chat, show-and-tell, wins, off-topic, and community-driven roadmap ideas.',
				'spaces'      => array(
					array(
						'title'       => 'General Discussion',
						'slug'        => 'general-discussion',
						'type'        => 'forum',
						'icon'        => 'message-circle',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => "Anything on your mind that doesn't fit a more specific space.",
					),
					array(
						'title'       => 'Show & Tell',
						'slug'        => 'show-and-tell',
						'type'        => 'forum',
						'icon'        => 'sparkles',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Built something with Jetonomy? Show the community what you made.',
					),
					array(
						'title'       => 'Off-Topic Lounge',
						'slug'        => 'off-topic-lounge',
						'type'        => 'forum',
						'icon'        => 'coffee',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Coffee, side projects, and everything unrelated to the product.',
					),
					array(
						'title'       => 'Wins & Milestones',
						'slug'        => 'wins-milestones',
						'type'        => 'feed',
						'icon'        => 'trophy',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Hit a milestone? Share the small wins and the big ones.',
					),
					array(
						'title'       => 'Roadmap Ideas',
						'slug'        => 'roadmap-ideas',
						'type'        => 'ideas',
						'icon'        => 'map',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Community-driven ideas for where the platform should go next.',
					),
				),
			),
			array(
				'name'        => 'Help & Support',
				'slug'        => 'help-support',
				'description' => 'Get unstuck — the help desk, troubleshooting, billing questions, the knowledge base, and the Insiders beta.',
				'spaces'      => array(
					array(
						'title'       => 'Help Desk',
						'slug'        => 'help-desk',
						'type'        => 'qa',
						'icon'        => 'life-buoy',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Ask a question, get a verified answer from the community and the team.',
					),
					array(
						'title'       => 'Troubleshooting',
						'slug'        => 'troubleshooting',
						'type'        => 'qa',
						'icon'        => 'wrench',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Errors, conflicts, and "it stopped working" — let us help you diagnose.',
					),
					array(
						'title'       => 'Billing & Accounts',
						'slug'        => 'billing-accounts',
						'type'        => 'forum',
						'icon'        => 'credit-card',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Licenses, renewals, upgrades, and account questions.',
					),
					array(
						'title'       => 'Knowledge Base',
						'slug'        => 'knowledge-base',
						'type'        => 'qa',
						'icon'        => 'book-open',
						'visibility'  => 'public',
						'join_policy' => 'open',
						'description' => 'Curated how-tos and reference answers, kept current by the team.',
					),
					array(
						'title'       => 'Insiders / Beta',
						'slug'        => 'insiders-beta',
						'type'        => 'forum',
						'icon'        => 'lock',
						'visibility'  => 'private',
						'join_policy' => 'approval',
						'description' => 'Early builds, private previews, and candid feedback. Approval required.',
					),
				),
			),
		);
	}

	/**
	 * Demo user definitions (~20): 2 moderators + 17 members.
	 *
	 * The site administrator already exists and is added separately by the
	 * generator. Trust levels span 0–5 and reputation tracks loosely with the
	 * trust level so leaderboard / profile screenshots read realistically.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function users(): array {
		return array(
			// Moderators.
			array(
				'login'       => 'maya',
				'display'     => 'Maya Okafor',
				'email'       => 'maya.demo@jetonomy.local',
				'role'        => 'moderator',
				'trust_level' => 5,
				'reputation'  => 1840,
				'bio'         => 'Community lead. Ten years running developer communities; here to keep things kind and useful.',
			),
			array(
				'login'       => 'theo',
				'display'     => 'Theo Lindqvist',
				'email'       => 'theo.demo@jetonomy.local',
				'role'        => 'moderator',
				'trust_level' => 5,
				'reputation'  => 1510,
				'bio'         => 'Support engineer and moderator. I live in the Troubleshooting space.',
			),
			// Members — trust levels spread 0–4.
			array(
				'login'       => 'alice',
				'display'     => 'Alice Chen',
				'email'       => 'alice.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 4,
				'reputation'  => 920,
				'bio'         => 'Community manager and content strategist. I help online communities grow.',
			),
			array(
				'login'       => 'bob',
				'display'     => 'Bob Martinez',
				'email'       => 'bob.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 4,
				'reputation'  => 760,
				'bio'         => 'Full-stack developer. Love building tools that connect people.',
			),
			array(
				'login'       => 'priya',
				'display'     => 'Priya Nair',
				'email'       => 'priya.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 3,
				'reputation'  => 540,
				'bio'         => 'Product designer. Obsessed with accessible interfaces and tidy empty states.',
			),
			array(
				'login'       => 'david',
				'display'     => 'David Kim',
				'email'       => 'david.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 3,
				'reputation'  => 480,
				'bio'         => 'WordPress consultant. I migrate communities off legacy forums for a living.',
			),
			array(
				'login'       => 'sofia',
				'display'     => 'Sofia Romano',
				'email'       => 'sofia.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 3,
				'reputation'  => 410,
				'bio'         => 'Developer advocate. I write the docs you wish you had read first.',
			),
			array(
				'login'       => 'jamal',
				'display'     => 'Jamal Reed',
				'email'       => 'jamal.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 2,
				'reputation'  => 290,
				'bio'         => 'Backend dev. Webhooks, queues, and the occasional regex crime.',
			),
			array(
				'login'       => 'lena',
				'display'     => 'Lena Fischer',
				'email'       => 'lena.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 2,
				'reputation'  => 245,
				'bio'         => 'Running a niche hobbyist community of about 4,000 members.',
			),
			array(
				'login'       => 'carlos',
				'display'     => 'Carlos Mendes',
				'email'       => 'carlos.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 2,
				'reputation'  => 210,
				'bio'         => 'Agency owner. We build community sites for clients on WordPress.',
			),
			array(
				'login'       => 'aisha',
				'display'     => 'Aisha Khan',
				'email'       => 'aisha.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 2,
				'reputation'  => 185,
				'bio'         => 'Front-end developer. Dark mode evangelist, keyboard-shortcut hoarder.',
			),
			array(
				'login'       => 'noah',
				'display'     => 'Noah Bennett',
				'email'       => 'noah.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 1,
				'reputation'  => 120,
				'bio'         => 'Indie maker. Building a paid community around my newsletter.',
			),
			array(
				'login'       => 'yuki',
				'display'     => 'Yuki Tanaka',
				'email'       => 'yuki.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 1,
				'reputation'  => 95,
				'bio'         => 'Learning WordPress development. Mostly here to read and ask good questions.',
			),
			array(
				'login'       => 'grace',
				'display'     => 'Grace Adeyemi',
				'email'       => 'grace.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 1,
				'reputation'  => 78,
				'bio'         => 'Nonprofit organizer setting up a members-only space for volunteers.',
			),
			array(
				'login'       => 'marco',
				'display'     => 'Marco Silva',
				'email'       => 'marco.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 1,
				'reputation'  => 62,
				'bio'         => 'Moving 3,000 bbPress topics over this month. Wish me luck.',
			),
			array(
				'login'       => 'elena',
				'display'     => 'Elena Petrova',
				'email'       => 'elena.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 1,
				'reputation'  => 54,
				'bio'         => 'Course creator. My students hang out here between lessons.',
			),
			array(
				'login'       => 'omar',
				'display'     => 'Omar Haddad',
				'email'       => 'omar.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 0,
				'reputation'  => 22,
				'bio'         => 'Just getting started. Excited to set up my first community.',
			),
			array(
				'login'       => 'hana',
				'display'     => 'Hana Park',
				'email'       => 'hana.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 0,
				'reputation'  => 14,
				'bio'         => 'New here! Evaluating Jetonomy for a small support community.',
			),
			array(
				'login'       => 'finn',
				'display'     => 'Finn O\'Brien',
				'email'       => 'finn.demo@jetonomy.local',
				'role'        => 'member',
				'trust_level' => 0,
				'reputation'  => 8,
				'bio'         => 'Lurker turned poster. Say hi if you see me around.',
			),
		);
	}

	/**
	 * Curated "anchor" topics keyed by space slug.
	 *
	 * ~5 specific, on-brand topics per space. The generator backfills each
	 * space to ~11 topics from {@see self::topic_pool()}. Each topic has a
	 * title, body (real HTML), and an author login that must match a
	 * {@see self::users()} login (or 'admin' for the site administrator).
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	public static function anchor_topics(): array {
		return array(
			// ── Start Here ──────────────────────────────────────────────────
			'announcements'        => array(
				array(
					'author'  => 'admin',
					'title'   => 'Welcome to the community',
					'content' => "<p>We're glad you're here. This is the home for everyone building communities with Jetonomy — site owners, developers, and the people who keep conversations healthy.</p><p>A few things to get you started:</p><ul><li>Skim the <strong>Community Guidelines</strong> so we're all on the same page.</li><li>Say hello in <strong>Introduce Yourself</strong>.</li><li>Ask anything in <strong>Getting Started</strong> — no question is too basic.</li></ul><p>Welcome aboard.</p>",
				),
				array(
					'author'  => 'maya',
					'title'   => 'New: dark mode is now available for all members',
					'content' => "<p>You asked, we shipped. Dark mode is now live for every member — toggle it from your profile preferences, independent of your system theme.</p><p>It respects the active theme's dark palette where one exists and falls back to a sensible default otherwise. Let us know how it reads on your setup.</p>",
				),
				array(
					'author'  => 'admin',
					'title'   => 'Scheduled maintenance this Saturday, 02:00–03:00 UTC',
					'content' => '<p>We will be performing a short database migration this Saturday between 02:00 and 03:00 UTC. The community may be read-only for up to fifteen minutes during that window.</p><p>No action is needed on your part. We picked the lowest-traffic hour to keep disruption minimal.</p>',
				),
				array(
					'author'  => 'maya',
					'title'   => 'Introducing our new moderator: Theo',
					'content' => '<p>Please welcome Theo to the moderation team. Many of you already know him from the Troubleshooting space, where he has quietly answered hundreds of questions.</p><p>Theo will be focusing on support and bug triage. Say hi when you see him around.</p>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'Community code of conduct update',
					'content' => '<p>We have refreshed the code of conduct with clearer language around self-promotion and a new section on reporting. Nothing fundamental has changed — we just made the existing expectations easier to read.</p><p>The full text lives in Community Guidelines. Questions are welcome there.</p>',
				),
			),
			'community-guidelines' => array(
				array(
					'author'  => 'admin',
					'title'   => 'Community guidelines — the short version',
					'content' => '<p>We keep things simple. Three principles:</p><ol><li><strong>Be respectful.</strong> Disagree with ideas, not with people.</li><li><strong>Be helpful.</strong> If someone asks a question, try to answer it or point them somewhere useful.</li><li><strong>Stay on topic.</strong> Each space has a purpose. Use the Off-Topic Lounge for everything else.</li></ol><p>Moderators keep conversations productive. If you see something that does not belong, use the flag button.</p>',
				),
				array(
					'author'  => 'maya',
					'title'   => 'How and when to use the flag button',
					'content' => "<p>Flagging is encouraged — it's the fastest way to keep the community healthy without heavy-handed moderation.</p><p>Flag content that is spam, off-topic, or breaks the code of conduct. Do <em>not</em> flag posts simply because you disagree with them. A reply you dislike is a chance to add a better answer, not a moderation case.</p>",
				),
				array(
					'author'  => 'maya',
					'title'   => 'Our self-promotion policy, explained',
					'content' => '<p>Sharing your work is welcome when it helps the conversation. The rule of thumb: contribute first, promote second.</p><ul><li>Answering a question with a link to your relevant tutorial: great.</li><li>Posting only links to your product across multiple spaces: not great.</li></ul><p>When in doubt, ask a moderator before posting.</p>',
				),
				array(
					'author'  => 'theo',
					'title'   => 'What happens when a post is flagged',
					'content' => '<p>A flagged post stays visible until a moderator reviews it. We see the report in the moderation queue, read the context, and either dismiss the flag or take action.</p><p>Repeated genuine flags on the same content raise its priority. False flags have no penalty, but please flag thoughtfully so the queue stays signal-rich.</p>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'Trust levels: how participation unlocks features',
					'content' => '<p>Trust levels reward consistent, constructive participation. As you post, reply, and earn reputation, you move up from Level 0 to Level 5.</p><p>Higher levels relax rate limits and unlock light moderation abilities. You never lose a level for being inactive — trust is earned, not rented.</p>',
				),
			),
			'introduce-yourself'   => array(
				array(
					'author'  => 'alice',
					'title'   => 'Hi all — community manager, longtime lurker',
					'content' => "<p>Hi everyone! I'm Alice. I manage communities for a living and I've been quietly reading here for a while. Finally making it official.</p><p>I'm most interested in onboarding flows and retention. Happy to trade notes with anyone working on the same.</p>",
				),
				array(
					'author'  => 'marco',
					'title'   => 'Migrating 3,000 bbPress topics — wish me luck',
					'content' => "<p>Hello! I'm Marco. I'm moving a 3,000-topic bbPress forum over to Jetonomy this month and I'm equal parts excited and nervous.</p><p>If you've done a migration of that size, I'd love to hear what surprised you.</p>",
				),
				array(
					'author'  => 'grace',
					'title'   => 'Nonprofit organizer setting up a volunteer space',
					'content' => "<p>Hi all, I'm Grace. I run volunteer programs for a small nonprofit and we need a members-only space to coordinate. Evaluating whether Jetonomy fits.</p><p>Any other nonprofits here? Would love to compare setups.</p>",
				),
				array(
					'author'  => 'yuki',
					'title'   => 'Learning WordPress dev, here to ask good questions',
					'content' => "<p>Hi! I'm Yuki. I'm fairly new to WordPress development and I joined mainly to learn. I'll be reading a lot and asking the occasional question in Getting Started.</p><p>Thanks in advance for your patience with a beginner.</p>",
				),
				array(
					'author'  => 'noah',
					'title'   => 'Indie maker building a paid newsletter community',
					'content' => "<p>Hey folks, Noah here. I run a newsletter and I'm adding a paid community tier around it. Looking at access rules and membership integrations.</p><p>Excited to learn from people who have done this already.</p>",
				),
			),
			'getting-started'      => array(
				array(
					'author'  => 'omar',
					'title'   => 'What is the difference between a category and a space?',
					'content' => '<p>Just getting started and I want to get the mental model right. A category seems to be a grouping, and a space is where the actual posts live — is that correct?</p><p>When should I create a new category versus just another space?</p>',
				),
				array(
					'author'  => 'hana',
					'title'   => 'How do I choose between forum, Q&A, ideas, and feed?',
					'content' => "<p>I'm setting up my first few spaces and I'm not sure which type to pick. What's the right use case for each of forum, Q&A, ideas, and feed?</p><p>A quick rule of thumb would help a lot.</p>",
				),
				array(
					'author'  => 'finn',
					'title'   => 'Where do I set the community base URL?',
					'content' => "<p>My community is showing up at <code>/community/</code> but I'd like it at <code>/forum/</code> instead. Where do I change the base slug?</p><p>Do I need to flush rewrite rules after changing it?</p>",
				),
				array(
					'author'  => 'omar',
					'title'   => 'Do members need a WordPress account to post?',
					'content' => '<p>Newbie question: do community members need a regular WordPress user account, or is there a separate profile system?</p><p>Trying to understand how registration and profiles fit together.</p>',
				),
				array(
					'author'  => 'grace',
					'title'   => 'How do I make a space members-only?',
					'content' => '<p>I want one space visible only to approved volunteers. I see visibility and join-policy settings — which combination gives me an approval-gated private space?</p>',
				),
			),
			'events-office-hours'  => array(
				array(
					'author'  => 'maya',
					'title'   => 'Office hours this Thursday at 16:00 UTC',
					'content' => '<p>Drop-in office hours this Thursday at 16:00 UTC. Bring your setup questions, migration plans, or anything you are stuck on. No agenda, just help.</p>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'Community AMA next week: ask the team anything',
					'content' => '<p>Next Wednesday we are hosting an AMA with the core team. Post your questions in advance and we will work through as many as we can live.</p>',
				),
				array(
					'author'  => 'theo',
					'title'   => 'Troubleshooting clinic — bring your stack traces',
					'content' => '<p>Running a troubleshooting clinic Friday. If you have an error you cannot crack, come with the steps to reproduce and we will debug it together.</p>',
				),
				array(
					'author'  => 'maya',
					'title'   => 'Recap: last month\'s migration workshop',
					'content' => '<p>Thanks to everyone who joined the migration workshop. We covered dry runs, date preservation, and batching. A written summary is on the way to the Knowledge Base.</p>',
				),
				array(
					'author'  => 'sofia',
					'title'   => 'New to events? Here is how office hours work',
					'content' => '<p>Office hours are informal video calls where you can ask anything. No registration, no slides — just show up with a question. Links are posted here an hour before each session.</p>',
				),
			),
			// ── Product & Engineering ───────────────────────────────────────
			'feature-discussions'  => array(
				array(
					'author'  => 'priya',
					'title'   => 'Should reactions replace simple upvotes on replies?',
					'content' => '<p>With reactions now available in Pro, I am wondering whether plain upvotes on replies still earn their keep. Reactions carry more nuance — heart, rocket, hooray.</p><p>Do you think both should coexist, or does that clutter the UI? Curious how others reason about this.</p>',
				),
				array(
					'author'  => 'bob',
					'title'   => 'Idea: per-space notification defaults set by the owner',
					'content' => '<p>Right now each member tunes their own notifications. It would help if the space owner could set a sensible default per space — for example, an Announcements space defaults to email-on-every-post.</p><p>Members could still override. Would this overstep, or is it a fair owner control?</p>',
				),
				array(
					'author'  => 'sofia',
					'title'   => 'How granular should the permission system get?',
					'content' => '<p>The three-layer model (WP caps → space roles → trust levels) is powerful. The risk is configurability becoming overwhelming.</p><p>Where is the line between flexible and confusing? I would rather have good defaults than a hundred toggles.</p>',
				),
				array(
					'author'  => 'aisha',
					'title'   => 'Keyboard-first navigation for the whole community',
					'content' => '<p>Power users live on the keyboard. We already have <code>j</code>/<code>k</code> navigation and a few shortcuts. I would love a fully keyboard-driven experience: jump to space, open composer, mark read.</p><p>What would your dream shortcut map look like?</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Threaded replies vs. flat — what works for support?',
					'content' => '<p>For client support sites we keep going back and forth on threaded versus flat replies. Threading helps tangents; flat keeps Q&A readable.</p><p>What has worked for your communities, and does the space type change your answer?</p>',
				),
			),
			'bug-reports'          => array(
				array(
					'author'  => 'jamal',
					'title'   => 'Reply count off by one after deleting a reply',
					'content' => "<p>Steps to reproduce: create a topic with three replies, delete one. The space listing still shows three replies on the topic.</p><p>It corrects itself after running <code>wp jetonomy recount</code>, so it looks like the denormalized counter isn't decremented on delete. WordPress 6.9, single site.</p>",
				),
				array(
					'author'  => 'lena',
					'title'   => 'Accepted-answer badge not showing on the reply',
					'content' => "<p>In a Q&A space I marked a reply as the accepted answer. The pinned callout at the top appears, but the green checkmark badge on the reply itself does not.</p><p>Hard refresh doesn't help. Is anyone else seeing this?</p>",
				),
				array(
					'author'  => 'aisha',
					'title'   => 'Dark mode toggle resets after navigating between spaces',
					'content' => "<p>When I enable dark mode and then click into a different space, the page loads in light mode for a split second before switching back. On slower connections it stays light.</p><p>Looks like the preference isn't applied before first paint.</p>",
				),
				array(
					'author'  => 'marco',
					'title'   => 'Import progress bar stalls at 100% but never finishes',
					'content' => '<p>Running a bbPress import of ~3,000 topics. The progress bar reaches 100% and then sits there. The data does import fully, but the UI never shows the success state.</p><p>Reloading the page shows everything imported correctly.</p>',
				),
				array(
					'author'  => 'yuki',
					'title'   => 'Search returns no results for terms with an apostrophe',
					'content' => "<p>Searching for <code>can't</code> or <code>O'Brien</code> returns nothing, even though posts containing those terms exist. Searching without the apostrophe works.</p><p>Looks like the apostrophe is breaking the query somewhere.</p>",
				),
			),
			'feature-requests'     => array(
				array(
					'author'  => 'aisha',
					'title'   => 'Dark mode toggle in user preferences',
					'content' => "<p>It would be great if users could switch to a dark color scheme directly from their profile preferences, independent of the system theme. Many of us work late and dark mode reduces eye strain.</p><p>Ideally it respects the theme's dark palette and falls back to a sensible default.</p>",
				),
				array(
					'author'  => 'alice',
					'title'   => 'Saved / bookmarked posts for quick reference',
					'content' => '<p>I often find great answers and have no way to save them. A simple bookmark button on posts and replies would be incredibly useful.</p><p>Bonus points for a "My Saved" page organized by space.</p>',
				),
				array(
					'author'  => 'bob',
					'title'   => 'Weekly digest email of top posts and new members',
					'content' => '<p>A weekly digest email — top posts, trending tags, new members — would do wonders for re-engagement. Let owners pick the cadence and members opt out.</p><p>This is the single biggest retention lever I keep reaching for.</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Bulk member import via CSV',
					'content' => '<p>For client launches we often need to seed a few hundred members at once. A CSV import — email, display name, starting space — would save hours of manual work.</p>',
				),
				array(
					'author'  => 'priya',
					'title'   => 'Per-space custom accent color',
					'content' => '<p>Letting each space carry its own accent color would make large communities far easier to navigate at a glance. It should still inherit the theme tokens by default.</p>',
				),
			),
			'api-integrations'     => array(
				array(
					'author'  => 'jamal',
					'title'   => 'Which webhook fires when a reply is accepted?',
					'content' => '<p>I want to notify an external system whenever a reply is accepted as the answer in a Q&A space. I see post and reply created events — is there a dedicated accepted-answer webhook, or do I listen for an update?</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Authenticating REST requests from a headless front end',
					'content' => "<p>We're building a headless front end and calling <code>jetonomy/v1</code> directly. What's the recommended auth approach — application passwords, nonces, or something else for server-to-server calls?</p>",
				),
				array(
					'author'  => 'bob',
					'title'   => 'Rate limits on the REST API — what are the defaults?',
					'content' => '<p>Before I build a sync job against the API, what rate limits should I expect? Are they tied to trust level like the on-site limits, or separate for API clients?</p>',
				),
				array(
					'author'  => 'noah',
					'title'   => 'Can I create posts on behalf of another user via REST?',
					'content' => '<p>For a cross-posting integration I need to create a post attributed to a specific member, not the API user. Is there a supported way to set the author on <code>POST /posts</code>?</p>',
				),
				array(
					'author'  => 'sofia',
					'title'   => 'Pagination contract for list endpoints',
					'content' => "<p>What's the pagination contract on list endpoints — page/per_page query args, and are total counts returned in headers? Want to build a correct paginator the first time.</p>",
				),
			),
			'release-notes'        => array(
				array(
					'author'  => 'admin',
					'title'   => 'Jetonomy 1.4.0 — reactions, polls, and faster search',
					'content' => '<p>1.4.0 is here. Highlights:</p><ul><li><strong>New</strong> — emoji reactions on posts and replies (Pro).</li><li><strong>New</strong> — single and multiple-choice polls (Pro).</li><li><strong>Improve</strong> — full-text search is noticeably faster on large communities.</li><li><strong>Fix</strong> — reply counters now stay accurate after deletes.</li></ul>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'Jetonomy 1.4.2 — accessibility and dark-mode polish',
					'content' => '<p>1.4.2 focuses on polish:</p><ul><li><strong>Improve</strong> — keyboard navigation across all community pages.</li><li><strong>Improve</strong> — dark mode applied before first paint to stop the flash.</li><li><strong>Fix</strong> — accepted-answer badge now renders on the reply itself.</li></ul>',
				),
				array(
					'author'  => 'maya',
					'title'   => 'Coming soon in 1.5.0 — a preview',
					'content' => '<p>A peek at what we are building for 1.5.0: a refreshed brand, a fully seeded model community for demos, and a documentation reshoot. More in the AMA next week.</p>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'Jetonomy 1.3.8 — import reliability',
					'content' => '<p>1.3.8 hardens the importers:</p><ul><li><strong>Improve</strong> — bbPress and wpForo imports now batch large datasets without timeouts.</li><li><strong>Fix</strong> — original post dates are preserved on import.</li></ul>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'Patch 1.4.1 — hotfix for notification preferences',
					'content' => '<p>A quick patch: 1.4.1 fixes a case where the notification-preferences section could be hidden for some members. Update at your convenience.</p>',
				),
			),
			// ── Community ───────────────────────────────────────────────────
			'general-discussion'   => array(
				array(
					'author'  => 'alice',
					'title'   => "What's everyone working on this week?",
					'content' => "<p>I always find it motivating to hear what others are building. I'm migrating a client's legacy forum over — the import tools have been surprisingly smooth.</p><p>What's on your plate? Drop a quick update below.</p>",
				),
				array(
					'author'  => 'bob',
					'title'   => 'The case for smaller, focused communities',
					'content' => '<p>Came across a thoughtful piece arguing that smaller, focused communities consistently outperform large social networks for professional learning. The core idea: signal beats reach.</p><p>Does that match your experience running communities?</p>',
				),
				array(
					'author'  => 'lena',
					'title'   => 'How do you keep a community active past the first month?',
					'content' => "<p>Launch energy fades fast. After the first month, what actually keeps people coming back in your communities?</p><p>I'm looking for repeatable rituals, not one-off campaigns.</p>",
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Self-hosted community vs. a SaaS platform — your take?',
					'content' => '<p>Clients always ask whether to self-host on WordPress or use a hosted community SaaS. I have my biases, but I want to steelman both sides.</p><p>What tips your recommendation one way or the other?</p>',
				),
				array(
					'author'  => 'priya',
					'title'   => 'Favorite onboarding flow you have seen anywhere',
					'content' => "<p>I'm collecting great onboarding flows — community or otherwise. What's the best first-run experience you have encountered, and what made it click?</p>",
				),
			),
			'show-and-tell'        => array(
				array(
					'author'  => 'noah',
					'title'   => 'Launched my paid newsletter community on Jetonomy',
					'content' => "<p>After two weeks of setup, my paid community is live. Access rules gate it behind the membership level, and the feed space is where I post between newsletters.</p><p>Early members love that it doesn't feel like another social network. Happy to share the config.</p>",
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Client launch: 1,200 members migrated in a weekend',
					'content' => '<p>We moved a client from a creaking phpBB install to Jetonomy over a weekend. 1,200 members, 8,000 posts, all dates preserved. The batched importer did the heavy lifting.</p><p>Screenshots of the before/after in the thread.</p>',
				),
				array(
					'author'  => 'elena',
					'title'   => 'My students now hang out here between lessons',
					'content' => "<p>I added a community to my course and it changed everything. Students answer each other's questions now, and the Q&A space has become a living FAQ.</p><p>Retention is up and my support load is down. Big win.</p>",
				),
				array(
					'author'  => 'lena',
					'title'   => 'Built a custom space template for our hobby niche',
					'content' => '<p>Overrode the space template in my theme to add a gear-list sidebar for our hobby community. The theme-override system made it painless — no core edits.</p>',
				),
				array(
					'author'  => 'aisha',
					'title'   => 'A dark-mode screenshot that finally looks right',
					'content' => '<p>Spent an evening tuning our theme tokens and dark mode finally looks intentional rather than inverted. Sharing a screenshot for anyone fighting the same fight.</p>',
				),
			),
			'off-topic-lounge'     => array(
				array(
					'author'  => 'bob',
					'title'   => 'What is on your desk right now?',
					'content' => '<p>Pure off-topic fun: what is physically on your desk as you read this? I will start — cold coffee, a rubber duck, and far too many sticky notes.</p>',
				),
				array(
					'author'  => 'yuki',
					'title'   => 'Mechanical keyboards: hobby or productivity tool?',
					'content' => '<p>Be honest — is your fancy keyboard actually making you faster, or is it just a delightful hobby? Mine is firmly the latter and I refuse to apologize.</p>',
				),
				array(
					'author'  => 'grace',
					'title'   => 'Best non-tech book you read this year',
					'content' => '<p>Looking to step away from screens. What is the best non-technical book you have read this year, and would you recommend it?</p>',
				),
				array(
					'author'  => 'finn',
					'title'   => 'Coffee or tea while you work?',
					'content' => '<p>The eternal debate. Coffee, tea, or something else entirely while you work? Bonus points for a specific brew you swear by.</p>',
				),
				array(
					'author'  => 'marco',
					'title'   => 'Side projects you will probably never finish',
					'content' => '<p>We all have them. What is the side project sitting at 70% done that you fully intend to finish someday? No judgment here.</p>',
				),
			),
			'wins-milestones'      => array(
				array(
					'author'  => 'noah',
					'title'   => 'Hit 100 paying members today',
					'content' => '<p>Crossed 100 paying members in the community this morning. Six months ago it was just me and a spreadsheet. Onward.</p>',
				),
				array(
					'author'  => 'elena',
					'title'   => 'First student-answered question with zero input from me',
					'content' => '<p>Small but huge: a student question got a complete, correct answer from another student before I even saw it. The community is starting to help itself.</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Shipped our fifth client community this quarter',
					'content' => '<p>Five client communities live this quarter, all on Jetonomy. The repeatable setup has turned a custom build into a productized service. Team is thrilled.</p>',
				),
				array(
					'author'  => 'lena',
					'title'   => 'Passed 4,000 members in our hobby community',
					'content' => '<p>We just passed 4,000 members. The thing that worked: a weekly "what are you working on" thread that people actually look forward to.</p>',
				),
				array(
					'author'  => 'alice',
					'title'   => 'Digest email open rate hit 47%',
					'content' => '<p>Our weekly digest email open rate hit 47% this week. Top posts plus a personal note from a moderator seems to be the magic combination.</p>',
				),
			),
			'roadmap-ideas'        => array(
				array(
					'author'  => 'priya',
					'title'   => 'Member-facing changelog inside the community',
					'content' => '<p>Idea: a built-in changelog space type that pulls from release notes and lets members react and comment per entry. Keeps "what changed" close to where people already are.</p>',
				),
				array(
					'author'  => 'jamal',
					'title'   => 'Scheduled posts for Announcements and Feeds',
					'content' => '<p>Let owners schedule posts in feed and announcement spaces. Useful for coordinating launches across time zones without staying up.</p>',
				),
				array(
					'author'  => 'sofia',
					'title'   => 'Public, read-only spaces for documentation',
					'content' => '<p>A read-only space type would be perfect for documentation — members can read and react but only the team can post. Bridges the gap between docs and forum.</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Multi-language communities with per-space locale',
					'content' => '<p>For agencies serving global clients, per-space locale would be huge — let each space declare its language so search and notifications respect it.</p>',
				),
				array(
					'author'  => 'noah',
					'title'   => 'Gamified onboarding checklist for new members',
					'content' => '<p>A short, gamified checklist for new members — complete your profile, make a post, join three spaces — with a badge at the end. Great for first-session retention.</p>',
				),
			),
			// ── Help & Support ──────────────────────────────────────────────
			'help-desk'            => array(
				array(
					'author'  => 'carol',
					'title'   => 'How do I customize the notification settings?',
					'content' => "<p>I'm getting email notifications for every reply in spaces I've joined. Is there a way to set a daily digest instead? I looked in profile settings but couldn't find it.</p><p>Running the latest version on WordPress 6.9 with the BuddyX theme.</p>",
				),
				array(
					'author'  => 'david',
					'title'   => 'Can I restrict a space to specific membership levels?',
					'content' => '<p>We have a premium tier using MemberPress. I want a space visible only to members at the "Pro" level and above.</p><p>I see an Access Rules section in the space settings — is that the right place, and what should it look like?</p>',
				),
				array(
					'author'  => 'grace',
					'title'   => 'How do I add a moderator to a single space?',
					'content' => '<p>I want one trusted volunteer to moderate just our volunteer space, not the whole community. Is space-level moderation a thing, or are moderators always global?</p>',
				),
				array(
					'author'  => 'hana',
					'title'   => 'Where do members edit their profile and avatar?',
					'content' => '<p>A member asked me where to change their avatar and bio and I realized I did not know. Where is the profile editor, and does it use the WordPress avatar or its own?</p>',
				),
				array(
					'author'  => 'omar',
					'title'   => 'How do I pin an important topic to the top of a space?',
					'content' => '<p>I have a welcome post I want pinned to the top of a space so new members always see it first. How do I pin a topic, and can I pin more than one?</p>',
				),
			),
			'troubleshooting'      => array(
				array(
					'author'  => 'marco',
					'title'   => 'Community pages return a 404 after changing the base slug',
					'content' => "<p>I changed the base slug from <code>community</code> to <code>forum</code> and now every community page 404s. The settings saved correctly.</p><p>I suspect rewrite rules — what's the right way to regenerate them?</p>",
				),
				array(
					'author'  => 'jamal',
					'title'   => 'Reactions not appearing even though Pro is active',
					'content' => "<p>Jetonomy Pro is active and licensed, but the reaction bar doesn't show on posts. Other Pro features work. Is there a per-space setting or a capability I'm missing?</p>",
				),
				array(
					'author'  => 'lena',
					'title'   => 'White screen on the moderation page only',
					'content' => '<p>Every community page loads fine except <code>/community/mod/</code>, which shows a white screen. Debug log mentions an undefined index. Single site, latest version.</p><p>Happy to share the full trace.</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Emails not sending — notifications silently fail',
					'content' => '<p>Members report they never receive notification emails. Test email from WordPress works, so SMTP is fine. The notification preferences look correct.</p><p>Where should I start looking?</p>',
				),
				array(
					'author'  => 'yuki',
					'title'   => 'Slow space listing on a community with many topics',
					'content' => '<p>One of my spaces has a few thousand topics and the listing page is sluggish. Others are fast. Is there an index or a setting I should check for large spaces?</p>',
				),
			),
			'billing-accounts'     => array(
				array(
					'author'  => 'noah',
					'title'   => 'How do I upgrade from free to Pro mid-cycle?',
					'content' => '<p>I am on the free plugin and want to add Pro now. Do I install Pro alongside free, and does my existing data carry over untouched?</p>',
				),
				array(
					'author'  => 'elena',
					'title'   => 'Does one license cover a staging site too?',
					'content' => '<p>I keep a staging copy for testing updates. Does my Pro license activate on both production and staging, or do I need a separate seat for staging?</p>',
				),
				array(
					'author'  => 'grace',
					'title'   => 'Nonprofit discount — is there one?',
					'content' => '<p>We are a registered nonprofit on a tight budget. Is there a nonprofit or educational discount for the Pro license? Happy to provide documentation.</p>',
				),
				array(
					'author'  => 'carlos',
					'title'   => 'Transferring a license between client sites',
					'content' => '<p>When a client engagement ends, can I deactivate the license on their site and move it to the next project, or are licenses bound to a single domain permanently?</p>',
				),
				array(
					'author'  => 'omar',
					'title'   => 'What happens to my community if the license lapses?',
					'content' => '<p>Hypothetically, if my Pro license lapses, do the Pro features just stop, or does anything risk breaking? Want to understand the downgrade path before I commit.</p>',
				),
			),
			'knowledge-base'       => array(
				array(
					'author'  => 'sofia',
					'title'   => 'How to migrate from bbPress without losing data',
					'content' => '<p>Reference guide. The bbPress importer preserves original dates, maps forums to spaces and forum categories to Jetonomy categories, and supports a dry run.</p><ol><li>Back up your database.</li><li>Run <code>wp jetonomy import bbpress --dry-run</code> to preview.</li><li>Run the import; it batches large datasets to avoid timeouts.</li></ol>',
				),
				array(
					'author'  => 'theo',
					'title'   => 'Understanding trust levels and what they unlock',
					'content' => '<p>Trust levels run 0–5 and are earned through participation. Level 0 has the tightest rate limits; higher levels relax them and unlock light moderation. Reputation and post activity drive promotion automatically.</p>',
				),
				array(
					'author'  => 'maya',
					'title'   => 'Setting up access rules for membership plugins',
					'content' => '<p>Jetonomy uses a universal adapter for memberships. Whether you run MemberPress or PMPro, the setup is identical: add an Access Rule on the space, set the type to Membership and the level you want, then set visibility to Private.</p>',
				),
				array(
					'author'  => 'sofia',
					'title'   => 'Overriding templates in your theme',
					'content' => '<p>Copy any file from the plugin\'s <code>templates/</code> directory into <code>your-theme/jetonomy/</code> and edit there. Your overrides survive plugin updates. Keep markup changes minimal so future template changes still apply cleanly.</p>',
				),
				array(
					'author'  => 'theo',
					'title'   => 'How notifications and digests are delivered',
					'content' => '<p>Notifications are event-driven and delivered through the active email adapter. Members choose instant, daily digest, or weekly digest per notification type in their profile. Digests require Pro.</p>',
				),
			),
			'insiders-beta'        => array(
				array(
					'author'  => 'maya',
					'title'   => 'Welcome to the Insiders space',
					'content' => "<p>You're in. This space is for early builds and candid feedback. What you see here is unfinished by design — that's the point.</p><p>Please keep screenshots inside this space until features ship publicly. Thank you for helping us get it right.</p>",
				),
				array(
					'author'  => 'admin',
					'title'   => 'Beta: scheduled posts — try it and tell us',
					'content' => '<p>Scheduled posts are now in beta for Insiders. You can schedule a post in feed and announcement spaces. Known rough edge: the scheduled badge does not yet show in the listing. Feedback welcome.</p>',
				),
				array(
					'author'  => 'theo',
					'title'   => 'Early look: the redesigned moderation queue',
					'content' => '<p>Here is an early build of the new moderation queue with bulk actions and inline context. It is not wired to keyboard shortcuts yet. Tell us what feels off before we lock it.</p>',
				),
				array(
					'author'  => 'priya',
					'title'   => 'Feedback thread: per-space accent colors',
					'content' => '<p>We are prototyping per-space accent colors. Drop your reactions here — does it help navigation, or is it visual noise at scale? Screenshots of your test spaces are very welcome.</p>',
				),
				array(
					'author'  => 'admin',
					'title'   => 'How we use your Insiders feedback',
					'content' => '<p>Everything posted here is read by the core team weekly. Highly-voted feedback often becomes a roadmap item within a release or two. Thank you for being early and honest.</p>',
				),
			),
		);
	}

	/**
	 * Templated title/body pool keyed by space type.
	 *
	 * The generator combines one of these templates with the space's own theme
	 * (its title) to backfill topics beyond the curated anchors, keeping titles
	 * and bodies varied across ~220 topics without runtime AI. Each entry has a
	 * `title` containing a single `%s` placeholder for the space title and a
	 * `content` HTML body.
	 *
	 * @param string $type Space type: forum|qa|ideas|feed.
	 * @return array<int, array<string, string>>
	 */
	public static function topic_pool( string $type ): array {
		$pools = array(
			'forum' => array(
				array(
					'title'   => 'A few thoughts on %s',
					'content' => '<p>I have been mulling over this space for a while and wanted to open a wider conversation. There is more nuance here than a single answer captures.</p><p>What perspectives am I missing? Genuinely curious where the community lands.</p>',
				),
				array(
					'title'   => 'What has worked for you in %s?',
					'content' => '<p>Rather than theorize, I would love concrete experiences. What has actually worked for you here, and what fell flat?</p><p>Specific stories beat general advice every time.</p>',
				),
				array(
					'title'   => 'A small thing I learned about %s',
					'content' => '<p>Nothing groundbreaking, but it saved me real time so I figured I would share. The trick was to slow down and get the basics right before optimizing.</p><p>Hope it helps someone else.</p>',
				),
				array(
					'title'   => 'Unpopular opinion about %s',
					'content' => '<p>Here is a take I rarely see shared. I might be wrong, and I am genuinely open to being talked out of it.</p><p>Convince me otherwise — that is half the fun of a good thread.</p>',
				),
				array(
					'title'   => 'Resources worth bookmarking for %s',
					'content' => '<p>Collecting the genuinely useful resources in one place so we are not all re-discovering them. I will start with a couple and let the thread fill in the rest.</p><ul><li>The official docs, which are better than people expect.</li><li>This community, honestly.</li></ul>',
				),
				array(
					'title'   => 'Where do you get stuck with %s?',
					'content' => '<p>Mapping the common sticking points so we can write better guides. Where do you most often get stuck, and what finally unblocked you?</p>',
				),
			),
			'qa'    => array(
				array(
					'title'   => 'What is the recommended way to handle %s?',
					'content' => '<p>I want to do this the supported way rather than hack around it. What is the recommended approach, and are there pitfalls to avoid?</p><p>Links to docs welcome if this is covered somewhere.</p>',
				),
				array(
					'title'   => 'Is this behavior in %s expected?',
					'content' => '<p>I am seeing something I cannot tell is a bug or by design. Before I file a report, can someone confirm whether this is expected behavior?</p><p>Happy to add reproduction steps if useful.</p>',
				),
				array(
					'title'   => 'How do I configure %s for a larger community?',
					'content' => '<p>What works at small scale gets shaky as a community grows. How should this be configured for a larger community — a few thousand members and up?</p>',
				),
				array(
					'title'   => 'Best practice question about %s',
					'content' => '<p>Looking for the community consensus rather than just "it works." What is considered best practice here, and why?</p>',
				),
				array(
					'title'   => 'Quick question on %s',
					'content' => '<p>Probably a simple one. I have read the docs but want to confirm before I change anything in production. Can someone sanity-check my understanding?</p>',
				),
				array(
					'title'   => 'Troubleshooting %s — where to start?',
					'content' => '<p>Something is not behaving and I am not sure where to begin. What should I check first, and what information would help others help me?</p>',
				),
			),
			'ideas' => array(
				array(
					'title'   => 'Idea: improve %s',
					'content' => '<p>A focused proposal to make this better without overcomplicating it. The goal is a small, high-leverage change rather than a sweeping redesign.</p><p>Vote it up if it would help you, and add detail in the replies.</p>',
				),
				array(
					'title'   => 'Could we streamline %s?',
					'content' => '<p>There is friction here that adds up over time. A streamlined flow would save everyone a little effort on every interaction.</p><p>Curious whether others feel the same pinch.</p>',
				),
				array(
					'title'   => 'Optional setting for %s',
					'content' => '<p>Not everyone needs this, so it should be opt-in with a sensible default. For the people who do need it, it would be a meaningful quality-of-life win.</p>',
				),
				array(
					'title'   => 'Better defaults for %s',
					'content' => '<p>Most of us never change the defaults, so getting them right matters more than adding toggles. Here is a proposal for defaults that would serve the typical community better.</p>',
				),
				array(
					'title'   => 'Make %s easier for newcomers',
					'content' => '<p>This trips up new members more than it should. A small guard rail or a clearer label would lower the barrier without getting in experienced users\' way.</p>',
				),
				array(
					'title'   => 'Power-user enhancement for %s',
					'content' => '<p>For the people who live in this part of the product, a small power-user enhancement would go a long way. It should stay invisible to everyone else.</p>',
				),
			),
			'feed'  => array(
				array(
					'title'   => 'Quick update on %s',
					'content' => '<p>A short note to keep everyone in the loop on this. Nothing urgent, just keeping the channel warm.</p>',
				),
				array(
					'title'   => 'Heads up about %s',
					'content' => '<p>Quick heads up for anyone following along here. Details to come, but wanted to flag it early.</p>',
				),
				array(
					'title'   => 'Sharing a small win in %s',
					'content' => '<p>Tiny win worth celebrating. These add up, and naming them keeps the momentum going.</p>',
				),
				array(
					'title'   => 'On the radar: %s',
					'content' => '<p>Something on the radar that you might care about. Filing it here so it is easy to find later.</p>',
				),
				array(
					'title'   => 'Reminder about %s',
					'content' => '<p>A friendly reminder so this does not slip past anyone. Reply if you have questions.</p>',
				),
				array(
					'title'   => 'This week in %s',
					'content' => '<p>A quick weekly note. Steady progress, nothing dramatic — exactly how we like it.</p>',
				),
			),
		);

		return $pools[ $type ] ?? $pools['forum'];
	}

	/**
	 * Generic reply snippets used to fill conversation threads.
	 *
	 * The generator picks from these for non-anchor replies, distributing
	 * authorship across users so no thread reads as a single-author wall.
	 *
	 * @return array<int, string>
	 */
	public static function reply_pool(): array {
		return array(
			'<p>This matches my experience exactly. The hard part is staying consistent once the initial novelty wears off.</p>',
			'<p>Great point. I had not considered the angle you raised in the second paragraph — it reframes the whole thing for me.</p>',
			'<p>We ran into the same situation last quarter. What worked for us was starting small and only adding complexity when a real need showed up.</p>',
			'<p>Thanks for writing this up. Bookmarking it — the kind of thing I will want to reference again in a month.</p>',
			'<p>I respectfully disagree on one detail. In my setup the opposite held true, though I suspect it comes down to community size.</p>',
			'<p>Could you say more about how you measured the result? I want to try this but I am not sure what success would look like.</p>',
			'<p>Adding a small note: the same idea applies on mobile, just with a bit more attention to tap targets.</p>',
			'<p>This is the answer I wish I had found six months ago. Would have saved me a weekend of trial and error.</p>',
			'<p>Solid breakdown. The only thing I would add is to back up before making the change — better safe than sorry.</p>',
			'<p>Following this thread closely. We are about to face the exact same decision and the replies here are gold.</p>',
			'<p>Tried this on a staging copy first and it behaved exactly as described. Confident enough to roll it to production now.</p>',
			'<p>One caveat for larger communities: keep an eye on the indexes. At a few thousand rows the difference is night and day.</p>',
			'<p>Appreciate the clear steps. The dry-run tip in particular saved me from a mistake I would not have caught otherwise.</p>',
			'<p>Strong agree. Good defaults beat a wall of toggles every single time.</p>',
			'<p>For what it is worth, the same approach works with the PMPro adapter — the membership side is identical from here.</p>',
			'<p>This deserves more votes. It is a small change with an outsized impact on day-to-day use.</p>',
		);
	}

	/**
	 * Accepted-answer bodies for Q&A spaces.
	 *
	 * The generator uses these for the reply it marks as the accepted answer on
	 * ~40% of questions, so the verified-answer callout reads authoritatively.
	 *
	 * @return array<int, string>
	 */
	public static function accepted_answers(): array {
		return array(
			'<p>Short answer: yes, and here is the supported path.</p><ol><li>Open the relevant settings panel.</li><li>Make the change there rather than editing files.</li><li>Save, then reload to confirm.</li></ol><p>Doing it this way means your change survives updates. Hand-editing files does not.</p>',
			'<p>This is expected behavior, not a bug. The guard you are hitting is intentional — it protects against a class of mistakes that used to cause data loss.</p><p>If you need the other behavior, there is a documented filter you can hook to override it per site.</p>',
			'<p>Go to your profile, then Edit, and scroll to the relevant section. The option you are looking for is there. If the section is hidden, confirm you are on a recent version — some controls were added in 1.4.</p>',
			'<p>Yes. The recommended approach is to use Access Rules on the space: set the rule type to Membership, pick the level, and set the space visibility to Private. Members at that level and above see it automatically; everyone else does not.</p>',
			'<p>Run <code>wp jetonomy flush-rules</code> (or visit Settings &gt; Permalinks and save) after changing the base slug. The 404s are stale rewrite rules — regenerating them fixes it immediately.</p>',
			'<p>The importer preserves original dates and maps forums to spaces. Always run with <code>--dry-run</code> first to preview counts, take a database backup, then run the real import. It batches large datasets so timeouts are not a concern.</p>',
			'<p>You can pin a topic from its action menu — look for "Pin to top." A space can hold more than one pinned topic; pinned items sort above the rest while keeping their own order.</p>',
			'<p>Trust levels are earned automatically through participation, so there is nothing to configure per user. If you want to grant an ability sooner, assign a space role instead — that takes effect immediately and is independent of trust level.</p>',
		);
	}

	/**
	 * Realistic tag vocabulary for the model community.
	 *
	 * The generator creates a {@see \Jetonomy\Models\Tag} for each name and
	 * attaches 1–3 relevant tags to most topics so the frontend tag pages and
	 * the tag cloud read like a real community rather than an empty stub. Names
	 * are display names — the Tag model derives the slug via sanitize_title().
	 *
	 * @return array<int, string>
	 */
	public static function tags(): array {
		return array(
			'getting-started',
			'moderation',
			'integrations',
			'api',
			'billing',
			'mobile',
			'search',
			'roadmap',
			'migration',
			'performance',
			'email',
			'webhooks',
		);
	}
}
