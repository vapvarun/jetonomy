(function($) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;

	// Owner's terminology (Settings > General > Terminology), localized as
	// window.jetonomyLabels; the English default covers a missing localize.
	const L = window.jetonomyLabels || {};
	const lbl = ( noun, form, fallback ) => ( L[ noun ] && L[ noun ][ form ] ) || fallback;
	const SPACE = lbl( 'space', 'one_lower', 'space' );
	const TOPIC = lbl( 'topic', 'one_lower', 'topic' );
	const TOPICS = lbl( 'topic', 'many_lower', 'topics' );
	const REPLY = lbl( 'reply', 'one_lower', 'reply' );
	const REPLIES = lbl( 'reply', 'many_lower', 'replies' );
	const MEMBER = lbl( 'member', 'one_lower', 'member' );
	const MEMBERS = lbl( 'member', 'many_lower', 'members' );
	const MEMBERS_CAP = lbl( 'member', 'many', 'Members' );

	var Jetonomy = {
		nonce: typeof jetonomyAdmin !== 'undefined' ? jetonomyAdmin.nonce : '',
		ajaxUrl: typeof jetonomyAdmin !== 'undefined' ? jetonomyAdmin.ajaxUrl : '',
		// UI copy, translated in the browser by wp.i18n (JSON files in languages/).
		i18n: {
			confirmDelete:        __( 'Are you sure? This cannot be undone.', 'jetonomy' ),
			/* translators: %s: the member label, lowercase (e.g. "member"). */
			confirmUnban:         sprintf( __( 'Lift this restriction? The %s can post again right away.', 'jetonomy' ), MEMBER ),
			/* translators: 1: space label, lowercase. 2: topic label, plural lowercase. 3: reply label, plural lowercase. 4: member label, plural (e.g. "Members"). */
			confirmArchiveSpace:  sprintf( __( 'Archive this %1$s and hand it to an administrator? Its %2$s and %3$s are kept and nothing is deleted. %4$s will no longer be able to post in it.', 'jetonomy' ), SPACE, TOPICS, REPLIES, MEMBERS_CAP ),
			/* translators: 1: space label, lowercase. 2: topic label, lowercase. 3: reply label, lowercase. 4: member label, plural lowercase. */
			confirmPurgeSpace:    sprintf( __( 'Permanently delete this %1$s and EVERY %2$s, %3$s and attachment in it, including content written by other %4$s? This cannot be undone.', 'jetonomy' ), SPACE, TOPIC, REPLY, MEMBERS ),
			// %1$s stays a literal %s, filled with the space name at click time.
			purgeTypeToConfirm:   sprintf( /* translators: 1: the space name the operator must retype. 2: topic label, lowercase. 3: reply label, lowercase. 4: member label, plural lowercase. 5: space label, lowercase. */ __( 'This destroys every %2$s, %3$s and attachment in %1$s, including content written by other %4$s. It cannot be undone. Type the %5$s name to confirm.', 'jetonomy' ), '%s', TOPIC, REPLY, MEMBERS, SPACE ),
			purgeConfirmLabel:    __( 'Delete permanently', 'jetonomy' ),
			confirmSpam:          __( 'Mark this as spam? It will be hidden from the community.', 'jetonomy' ),
			confirmTrash:         __( 'Move this to trash? This removes it from the community.', 'jetonomy' ),
			saving:               __( 'Saving…', 'jetonomy' ),
			saved:                __( 'Saved!', 'jetonomy' ),
			error:                __( 'Something went wrong.', 'jetonomy' ),
			importDone:           __( 'Import complete!', 'jetonomy' ),
			/* translators: %s: server-supplied error detail. */
			importErrorFormat:    __( 'Error: %s', 'jetonomy' ),
			importErrorUnknown:   __( 'Unknown error', 'jetonomy' ),
			selectImage:          __( 'Select Image', 'jetonomy' ),
			useImage:             __( 'Use this image', 'jetonomy' ),
			unban:                __( 'Lift restriction', 'jetonomy' ),
			ban:                  __( 'Ban', 'jetonomy' ),
			/* translators: %s: space label, plural lowercase (e.g. "spaces"). */
			hiddenForcesInvite:   sprintf( __( 'Hidden %s must use Invite Only. Join policy switched.', 'jetonomy' ), lbl( 'space', 'many_lower', 'spaces' ) ),
			hiddenRequiresInvite: __( 'Switched visibility to Private because Hidden requires Invite Only.', 'jetonomy' ),
			/* translators: %s: space label, plural lowercase (e.g. "spaces"). */
			privateForcesApproval: sprintf( __( 'Private %s need approval or an invite. Join policy switched to Approval Required.', 'jetonomy' ), lbl( 'space', 'many_lower', 'spaces' ) ),
			/* translators: %s: space label, lowercase (e.g. "space"). */
			openRequiresPublic:   sprintf( __( 'Switched visibility to Public because anyone-can-join needs a public %s.', 'jetonomy' ), SPACE ),
			reloadPage:           __( 'Reload page', 'jetonomy' ),
			importConnectionLost: __( 'Connection lost. You can resume this import later.', 'jetonomy' ),
			inviteCopied:         __( 'Invite link copied to clipboard.', 'jetonomy' ),
			inviteRevokeConfirm:  __( 'Revoke this invite link? Anyone holding it will no longer be able to join.', 'jetonomy' ),
			inviteUnlimited:      __( 'Unlimited', 'jetonomy' ),
			inviteNever:          __( 'Never', 'jetonomy' ),
			inviteExpired:        __( 'Expired', 'jetonomy' ),
			inviteLoadMore:       __( 'Load more', 'jetonomy' ),
			// Column labels for JS-injected invite rows. They must match the
			// headings jetonomy_admin_table() renders, because the responsive
			// layout shows them as each cell's label on mobile.
			inviteLink:           __( 'Invite Link', 'jetonomy' ),
			inviteUses:           __( 'Uses', 'jetonomy' ),
			inviteExpires:        __( 'Expires', 'jetonomy' ),
			actions:              __( 'Actions', 'jetonomy' ),
			showMoreDetails:      __( 'Show more details', 'jetonomy' ),
			copy:                 __( 'Copy', 'jetonomy' ),
			revoke:               __( 'Revoke', 'jetonomy' ),
			prefixLabel:          __( 'Label', 'jetonomy' ),
			removePrefix:         __( 'Remove prefix', 'jetonomy' ),
			sync:                 __( 'Sync', 'jetonomy' ),
			syncing:              __( 'Syncing…', 'jetonomy' ),
			/* translators: %d: number of memberships synced. */
			syncedFormat:         __( 'Synced (%d)', 'jetonomy' ),
			importRestartConfirm: __( 'This will discard the interrupted import progress. Continue?', 'jetonomy' ),
			importRestartTitle:   __( 'Restart import', 'jetonomy' ),
			// Access-rule composer preview: the sentence and its "who matches" notes.
			rulePreview: {
				whoFallback: __( 'People who match this rule', 'jetonomy' ),
				/* translators: 1: who the rule matches, 2: what they may do, 3: the space role they are recorded as. */
				sentence: __( '%1$s can %2$s. They are recorded as %3$s.', 'jetonomy' ),
				// A rule admits; it does not restrict, so no "but not take part".
				grants: {
					/* translators: %s: reply label, plural lowercase (e.g. "replies"). */
					read:        sprintf( __( 'read and report posts and %s', 'jetonomy' ), REPLIES ),
					participate: __( 'read, post, reply, vote and report', 'jetonomy' ), // jt-terms-ok: "reply" is the verb here, not the noun.
					full:        sprintf( /* translators: %s: topic label, plural lowercase (e.g. "topics"). */ __( 'read, post, reply, vote, report, and - if their WordPress role already allows moderation - edit, close or pin other people\'s %s', 'jetonomy' ), TOPICS ), // jt-terms-ok: "reply" is the verb; the noun is the placeholder.
				},
				// The consequence of each rule type, not its definition.
				typeNotes: {
					everyone:    __( 'Matches every visitor, signed in or not. Nobody is asked to log in first.', 'jetonomy' ),
					logged_in:   __( 'Matches anyone with an account on this site, whoever they are. A new registration matches the moment it is created.', 'jetonomy' ),
					/* translators: %s: member label, plural lowercase (e.g. "members"). */
					role:        sprintf( __( 'Matches anyone holding this WordPress role. Most %s hold Subscriber, the role WordPress gives new registrations, so a Subscriber rule usually means "everyone who signed up".', 'jetonomy' ), MEMBERS ),
					capability:  __( 'Matches anyone whose WordPress role carries this capability. Use it when several roles should match one rule, or when another plugin grants the capability on the fly.', 'jetonomy' ),
					/* translators: %s: member label, plural lowercase (e.g. "members"). */
					trust_level: sprintf( __( 'Matches %s at or above this trust level, 1 to 5. Trust is earned by taking part, so this rule lets more people in over time without you touching it.', 'jetonomy' ), MEMBERS ),
				},
				// %1$s stays a literal %s, filled with the adapter name at runtime.
				membershipNote: sprintf( /* translators: 1: the membership adapter's name, e.g. "WooCommerce Memberships". Adapters register their own rule types at runtime, so they share this note. 2: member label, plural lowercase. */ __( 'Matches %2$s who hold the %1$s level you pick below.', 'jetonomy' ), '%s', MEMBERS ),
				// One generic example cannot serve a role slug, a capability and a number.
				typePlaceholders: {
					role:        __( 'subscriber', 'jetonomy' ),
					capability:  __( 'edit_posts', 'jetonomy' ),
					trust_level: __( '2', 'jetonomy' ),
				},
				// Roster-role labels for the derived value in the preview.
				roles: {
					viewer:    __( 'Viewer', 'jetonomy' ),
					member:    __( 'Member', 'jetonomy' ), // jt-terms-ok: space role name.
					moderator: __( 'Moderator', 'jetonomy' ),
					admin:     __( 'Admin', 'jetonomy' ),
				},
			},
		},

		init: function() {
			this.showPendingToast();
			this.bindDashboard();
			this.bindCategoryActions();
			this.bindSpaceActions();
			this.bindInviteLinks();
			this.bindModerationActions();
			this.bindUserActions();
			this.bindImport();
			this.bindSettings();
			this.initColorPickers();
			this.initCodeEditor();
			this.initMediaUploaders();
			this.bindSlugGeneration();
		},

		// ── List page context ──

		// Drag-reorder submits only the rows the browser rendered, so any handler
		// persisting a position needs to know which page those rows came from.
		// Read from the URL rather than the DOM: it is the same source the server
		// paginated with, so the two can never disagree.
		listPageContext: function() {
			var params = new URLSearchParams(window.location.search);
			var paged = parseInt(params.get('paged'), 10);
			var perPage = parseInt(params.get('per_page'), 10);
			return {
				paged: paged > 0 ? paged : 1,
				perPage: [20, 50, 100].indexOf(perPage) !== -1 ? perPage : 20
			};
		},

		// ── AJAX Helper ──

		ajax: function(action, data) {
			data = data || {};
			data.action = action;
			data.nonce = this.nonce;
			return $.post(this.ajaxUrl, data);
		},

		// ── Confirm helper (1.4.0) ──
		// Wraps the shared modal toolkit (assets/js/jetonomy-modals.js) so
		// every wp-admin "Are you sure?" prompt uses the same dialog as
		// the front end. Returns a Promise resolving true/false. Per the
		// no-browser-alerts rule, we NEVER fall back to native confirm()
		// even on wp-admin — the toolkit is a hard dependency. If it's
		// somehow absent the helper resolves false (cancel) so the
		// destructive action never silently runs.
		confirmAsync: function(message, opts) {
			if (typeof window.jetonomyConfirm !== 'function') {
				return Promise.resolve(false);
			}
			return window.jetonomyConfirm(message, opts || {});
		},

		// ── Toast Notification ──

		toast: function(message, type) {
			type = type || 'success';
			var $toast = $('<div class="jetonomy-toast jetonomy-toast--' + type + '">' + $('<span>').text(message).html() + '</div>');
			$('body').append($toast);
			setTimeout(function() {
				$toast.remove();
			}, 3200);
		},

		// A toast fired right before a reload or navigation dies with the page,
		// so the message is parked in sessionStorage and shown on the next load.
		reloadWithToast: function(message, url) {
			try {
				window.sessionStorage.setItem('jetonomyAdminToast', String(message || ''));
			} catch (e) {
				// Storage blocked (private mode): the page still reloads, just without the toast.
			}
			if (url) {
				window.location.href = url;
			} else {
				window.location.reload();
			}
		},

		showPendingToast: function() {
			var message = '';
			try {
				message = window.sessionStorage.getItem('jetonomyAdminToast') || '';
				window.sessionStorage.removeItem('jetonomyAdminToast');
			} catch (e) {
				// Storage blocked: nothing was parked, so there is no toast to show.
			}
			if (message) {
				this.toast(message);
			}
		},

		// ── Spinner Helper ──

		showSpinner: function($el) {
			$el.closest('.submit, p, .jetonomy-modal__actions').find('.spinner').addClass('is-active');
		},

		hideSpinner: function($el) {
			$el.closest('.submit, p, .jetonomy-modal__actions').find('.spinner').removeClass('is-active');
		},

		// ── Slug Auto-generation ──

		bindSlugGeneration: function() {
			$(document).on('blur', '#cat-name', function() {
				var $slug = $('#cat-slug');
				if (!$slug.val()) {
					$slug.val(Jetonomy.slugify($(this).val()));
				}
			});
			$(document).on('blur', '#space-title', function() {
				var $slug = $('#space-slug');
				if (!$slug.val()) {
					$slug.val(Jetonomy.slugify($(this).val()));
				}
			});
		},

		slugify: function(text) {
			return text.toString().toLowerCase().trim()
				.replace(/[^\w\s-]/g, '')
				.replace(/[\s_]+/g, '-')
				.replace(/^-+|-+$/g, '');
		},

		// ── Color Pickers ──

		initColorPickers: function() {
			$('.jetonomy-color-picker').each(function() {
				if (!$(this).hasClass('wp-color-picker')) {
					$(this).wpColorPicker();
				}
			});

			// Colour is a top-level accent only: a sub-category never shows or
			// stores one, so its field is hidden while a parent is chosen.
			$(document).on('change', '#cat-parent, #edit-cat-parent', this.syncColorFields);
			this.syncColorFields();
		},

		syncColorFields: function() {
			$('[data-jt-color-field]').each(function() {
				var parent = parseInt($($(this).data('jtColorField')).val(), 10) || 0;
				$(this).prop('hidden', parent > 0);
			});
		},

		// ── Code Editor ──

		initCodeEditor: function() {
			if (typeof jetonomyCmSettings !== 'undefined' && $('#custom_css').length) {
				wp.codeEditor.initialize($('#custom_css'), jetonomyCmSettings);
			}
		},

		// ── Media Uploaders ──

		initMediaUploaders: function() {
			var self = this;

			$(document).on('click', '#space-cover-upload', function(e) {
				e.preventDefault();
				var frame = wp.media({
					title: self.i18n.selectImage,
					button: { text: self.i18n.useImage },
					multiple: false,
					library: { type: 'image' }
				});

				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#space-cover-image').val(attachment.url);
					var $preview = $('#space-cover-preview');
					if ($preview.find('img').length) {
						$preview.find('img').attr('src', attachment.url);
					} else {
						$preview.prepend($('<img alt="">').attr('src', attachment.url));
					}
					$preview.show();
				});

				frame.open();
			});

			$(document).on('click', '.jetonomy-remove-cover', function(e) {
				e.preventDefault();
				$('#space-cover-image').val('');
				$('#space-cover-preview').hide().find('img').attr('src', '');
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Dashboard
		// ═══════════════════════════════════════════════════════════

		bindDashboard: function() {
			var self = this;

			$(document).on('click', '#jetonomy-flush-rules', function() {
				var $btn = $(this);
				$btn.prop('disabled', true);
				self.ajax('jetonomy_flush_rules').done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Categories
		// ═══════════════════════════════════════════════════════════

		bindCategoryActions: function() {
			var self = this;

			// Create Category
			$(document).on('click', '#jetonomy-save-category', function() {
				var $btn = $(this);
				var name = $('#cat-name').val().trim();
				if (!name) {
					$('#cat-name').focus();
					return;
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_create_category', {
					name: name,
					slug: $('#cat-slug').val(),
					description: $('#cat-description').val(),
					parent_id: $('#cat-parent').val(),
					icon: ($('input[name="icon"]', '#jetonomy-add-category-form').filter(':checked').val() || ''),
					color: $('#cat-color').val(),
					visibility: $('#cat-visibility').val()
				}).done(function(res) {
					if (res.success) {
						self.reloadWithToast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Edit Category - Open Modal
			$(document).on('click', '.jetonomy-edit-category', function(e) {
				e.preventDefault();
				var $link = $(this);
				$('#edit-cat-id').val($link.data('id'));
				$('#edit-cat-name').val($link.data('name'));
				$('#edit-cat-slug').val($link.data('slug'));
				$('#edit-cat-description').val($link.data('description'));
				$('#edit-cat-parent').val($link.data('parent'));
				// Sync the picker selection inside the edit modal to the row's saved icon.
				(function () {
					var savedIcon = String($link.data('icon') || '');
					$('input[name="icon"]', '#jetonomy-edit-category-modal').each(function () {
						var match = this.value === savedIcon;
						this.checked = match;
						$(this).closest('.jt-icon-option').toggleClass('is-selected', match);
					});
				})();
				$('#edit-cat-visibility').val($link.data('visibility'));
				$('#edit-cat-id').data('edit-token', $link.attr('data-edit-token') || '');

				// Re-initialize color picker in modal
				var $color = $('#edit-cat-color');
				if ($color.closest('.wp-picker-container').length) {
					$color.wpColorPicker('color', $link.data('color') || '');
				} else {
					$color.val($link.data('color') || '');
					$color.wpColorPicker();
				}
				self.syncColorFields();

				$('#jetonomy-edit-category-modal').show();
			});

			// Update Category
			$(document).on('click', '#jetonomy-update-category', function() {
				var $btn = $(this);
				var id = $('#edit-cat-id').val();
				var name = $('#edit-cat-name').val().trim();
				if (!name) {
					$('#edit-cat-name').focus();
					return;
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_update_category', {
					id: id,
					edit_token: $('#edit-cat-id').data('edit-token') || '',
					name: name,
					slug: $('#edit-cat-slug').val(),
					description: $('#edit-cat-description').val(),
					parent_id: $('#edit-cat-parent').val(),
					icon: ($('input[name="icon"]', '#jetonomy-edit-category-modal').filter(':checked').val() || ''),
					color: $('#edit-cat-color').val(),
					visibility: $('#edit-cat-visibility').val()
				}).done(function(res) {
					if (res.success) {
						self.reloadWithToast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Delete Category
			$(document).on('click', '.jetonomy-delete-category', function(e) {
				e.preventDefault();
				var $row = $(this).closest('tr');
				var id = $row.data('id');

				self.confirmAsync(self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_delete_category', { id: id }).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() { $(this).remove(); });
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
					});
				});
			});

			// Drag-sort Categories. A parent reorders among the top-level
			// categories, a sub-category among its siblings. After any drop the
			// rows are regrouped so every sub-category sits under its own parent
			// again: a dragged parent carries its sub-categories, and a
			// sub-category dropped elsewhere keeps only its order among siblings.
			if ($('#jetonomy-categories-list').length) {
				var $catList = $('#jetonomy-categories-list');
				$catList.sortable({
					handle: '.jetonomy-drag-handle',
					placeholder: 'ui-sortable-placeholder',
					update: function(event, ui) {
						var parentId = parseInt(ui.item.data('parent'), 10) || 0;
						var $children = $catList.children('tr.jetonomy-category-child');

						$catList.children('tr[data-id]').not('.jetonomy-category-child').each(function() {
							var $parent = $(this);
							$parent.after($children.filter('[data-parent="' + $parent.data('id') + '"]'));
						});

						var order = [];
						$catList.children('tr[data-id][data-parent="' + parentId + '"]').each(function() {
							order.push($(this).data('id'));
						});
						// Top-level rows are one page of a paginated list, so the
						// handler needs the page context to turn them into absolute
						// positions. A sibling group always
						// renders whole under its parent.
						var ctx = self.listPageContext();
						self.ajax('jetonomy_reorder_categories', {
							order: order,
							parent_id: parentId,
							paged: ctx.paged,
							per_page: ctx.perPage
						}).done(function(res) {
							if (res.success) {
								self.toast(res.data.message);
							}
						});
					}
				});
			}

			// Close Modal
			$(document).on('click', '.jetonomy-modal-close, .jetonomy-modal__overlay', function() {
				$(this).closest('.jetonomy-modal').hide();
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Spaces
		// ═══════════════════════════════════════════════════════════

		bindSpaceActions: function() {
			var self = this;

			// Drag-sort Spaces. Only rendered when one category is filtered —
			// manual order is per-category, matching Space::list_by_category().
			if ($('#jetonomy-spaces-list').length && $('#jetonomy-spaces-list .jetonomy-drag-handle').length) {
				$('#jetonomy-spaces-list').sortable({
					handle: '.jetonomy-drag-handle',
					placeholder: 'ui-sortable-placeholder',
					update: function() {
						var order = [];
						$('#jetonomy-spaces-list tr[data-id]').each(function() {
							order.push($(this).data('id'));
						});
						var ctx = self.listPageContext();
						self.ajax('jetonomy_reorder_spaces', {
							order: order,
							paged: ctx.paged,
							per_page: ctx.perPage
						}).done(function(res) {
							if (res.success) {
								self.toast(res.data.message);
							}
						});
					}
				});
			}

			// Visibility ↔ Join Policy coupling: hidden spaces must be
			// invite-only. Server-side validation rejects the bad combo;
			// this handler stops the user from tripping the rejection in
			// the first place by snapping the dropdown when Hidden is
			// chosen. Reverse direction (changing join_policy away from
			// invite while Hidden) flips visibility back to Private with
			// an inline note so the user understands what happened.
			var coupleVisibilityJoinPolicy = function() {
				var $vis  = $('#space-visibility');
				var $join = $('#space-join-policy');
				if (!$vis.length || !$join.length) {
					return;
				}
				var noteId = 'space-visibility-coupling-note';
				var ensureNote = function(message) {
					var $note = $('#' + noteId);
					if (!$note.length) {
						$note = $('<p id="' + noteId + '" class="description jt-admin-msg jt-admin-msg--info" role="status" aria-live="polite"></p>');
						$join.after($note);
					}
					$note.text(message).show();
					setTimeout(function() { $note.fadeOut(2400); }, 4000);
				};
				$vis.off('change.jtCouple').on('change.jtCouple', function() {
					if ($(this).val() === 'hidden' && $join.val() !== 'invite') {
						$join.val('invite').trigger('change.jtCoupleSilent');
						ensureNote(self.i18n.hiddenForcesInvite);
					} else if ($(this).val() === 'private' && $join.val() === 'open') {
						$join.val('approval').trigger('change.jtCoupleSilent');
						ensureNote(self.i18n.privateForcesApproval);
					}
				});
				$join.off('change.jtCouple').on('change.jtCouple', function(e) {
					if (e.namespace === 'jtCoupleSilent') {
						return;
					}
					if ($vis.val() === 'hidden' && $(this).val() !== 'invite') {
						$vis.val('private');
						ensureNote(self.i18n.hiddenRequiresInvite);
					} else if ($vis.val() === 'private' && $(this).val() === 'open') {
						$vis.val('public');
						ensureNote(self.i18n.openRequiresPublic);
					}
				});
			};
			coupleVisibilityJoinPolicy();

			// Filter Spaces
			$(document).on('click', '#jetonomy-filter-spaces', function() {
				var params = {
					page: 'jetonomy-spaces',
					category_id: $('#filter-category').val(),
					type: $('#filter-type').val(),
					status: $('#filter-status').val()
				};
				var url = new URL(window.location.href);
				Object.keys(params).forEach(function(key) {
					if (params[key]) {
						url.searchParams.set(key, params[key]);
					} else {
						url.searchParams.delete(key);
					}
				});
				window.location.href = url.toString();
			});

			// Create Space
			$(document).on('submit', '#jetonomy-new-space-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $btn = $form.find('[type="submit"]');
				var title = $('#space-title').val().trim();
				if (!title) {
					$('#space-title').focus();
					return;
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_create_space', {
					title: title,
					slug: $('#space-slug').val(),
					description: $('#space-description').val(),
					category_id: $('#space-category').val(),
					type: $('#space-type').val(),
					visibility: $('#space-visibility').val(),
					join_policy: $('#space-join-policy').val(),
					status: $('#space-status').val(),
					icon: ($('input[name="icon"]:checked').val() || ''),
					cover_image: $('#space-cover-image').val()
				}).done(function(res) {
					if (res.success) {
						self.reloadWithToast(res.data.message, 'admin.php?page=jetonomy-spaces&action=edit&space_id=' + res.data.id);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Update Space (Edit Form)
			$(document).on('submit', '#jetonomy-edit-space-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $btn = $form.find('[type="submit"]');
				var spaceId = $form.data('space-id');

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_update_space', {
					id: spaceId,
					edit_token: $form.attr('data-edit-token') || '',
					title: $('#space-title').val(),
					slug: $('#space-slug').val(),
					description: $('#space-description').val(),
					category_id: $('#space-category').val(),
					type: $('#space-type').val(),
					visibility: $('#space-visibility').val(),
					join_policy: $('#space-join-policy').val(),
					status: $('#space-status').val(),
					sort_order: $('#space-sort-order').val(),
					icon: ($('input[name="icon"]:checked').val() || ''),
					cover_image: $('#space-cover-image').val()
				}).done(function(res) {
					if (res.success) {
						// The form stays open, so its next save is checked against what was just saved.
						$form.attr('data-edit-token', res.data.edit_token || '');
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// Delete Space
			$(document).on('click', '.jetonomy-delete-space', function(e) {
				e.preventDefault();
				var $row = $(this).closest('tr');
				var id = $(this).data('id');
				var mode = $(this).data('mode') || 'transfer';
				var title = String($(this).data('title') || '');

				// Purge has no undo, so it asks the operator to TYPE the space
				// name rather than click through a dialog. Archive keeps every
				// topic and stays a plain confirm - matching the weight of the
				// warning to the weight of the action is the point.
				var gate;
				if (mode === 'purge' && title && typeof window.jetonomyPrompt === 'function') {
					gate = window.jetonomyPrompt(
						(self.i18n.purgeTypeToConfirm).replace('%s', title),
						{
							danger: true,
							requireMatch: title,
							placeholder: title,
							confirmLabel: self.i18n.purgeConfirmLabel
						}
					).then(function(typed) {
						// null = cancelled. The dialog explains a mismatch and
						// should only resolve on an exact match, but this is the
						// one check the purge request relies on, so it never
						// trusts the dialog alone.
						return typed !== null && String(typed).trim() === title;
					});
				} else {
					var warning = mode === 'purge'
						? (self.i18n.confirmPurgeSpace || self.i18n.confirmDelete)
						: (self.i18n.confirmArchiveSpace || self.i18n.confirmDelete);
					gate = self.confirmAsync(warning, { danger: mode === 'purge' });
				}

				gate.then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_delete_space', { id: id, mode: mode }).done(function(res) {
						if (res.success) {
							// An archived space still exists, so the row stays and
							// is reloaded; only a purge removes it from the list.
							if (res.data && res.data.removed) {
								self.toast(res.data.message);
								$row.fadeOut(300, function() { $(this).remove(); });
							} else {
								self.reloadWithToast(res.data.message);
							}
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
					});
				});
			});

			// ── Topic Prefix Repeater ──
			$(document).on('change', '#ss-enable-prefixes', function() {
				$('#jt-prefixes-config').toggle(this.checked);
			});
			$(document).on('click', '#jt-add-prefix', function() {
				var labelPlaceholder = Jetonomy.i18n.prefixLabel;
				var removeTitle      = Jetonomy.i18n.removePrefix;
				var row = '<div class="jt-prefix-row">' +
					'<input type="text" class="jt-prefix-name" placeholder="' + $('<div>').text(labelPlaceholder).html() + '" maxlength="50">' +
					'<input type="color" class="jt-prefix-color" value="#3B82F6">' +
					'<button type="button" class="button jt-prefix-remove" title="' + $('<div>').text(removeTitle).html() + '">&times;</button>' +
					'</div>';
				$('#jt-prefixes-list').append(row);
			});
			$(document).on('click', '.jt-prefix-remove', function() {
				$(this).closest('.jt-prefix-row').remove();
			});

			// Space Settings Form
			$(document).on('submit', '#jetonomy-space-settings-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $btn = $form.find('[type="submit"]');
				var spaceId = $form.data('space-id');

				var settings = {};
				var whoCanPost = $('#ss-who-can-post').val();
				var whoCanReply = $('#ss-who-can-reply').val();
				var requireApproval = $('#ss-require-approval').is(':checked');
				var allowVoting = $('#ss-allow-voting').is(':checked');
				var postsPerPage = $('#ss-posts-per-page').val();

				// Send '' for the empty option so merge_settings() UNSETS the key.
				// '' is never coerced to 'members': that would write a restriction the owner never
				// chose, turning "no restriction" into Members Only whenever any field on this tab
				// (posts per page, voting, prefixes) is touched.
				settings.who_can_post = whoCanPost || '';
				settings.who_can_reply = whoCanReply || '';
				settings.require_approval = requireApproval ? '1' : '0';
				settings.allow_voting = allowVoting ? '1' : '0';
				// Save null when empty so Space::get_posts_per_page() can resolve via the
				// space → global → 20 fallback chain. Coercing empty to 20 here would
				// poison the per-space settings JSON and override the global default.
				var ppNum = parseInt(postsPerPage, 10);
				settings.posts_per_page = (postsPerPage === '' || isNaN(ppNum) || ppNum <= 0)
					? null
					: Math.max(1, Math.min(100, ppNum));

				// Collect topic prefixes.
				var enablePrefixes = $('#ss-enable-prefixes').is(':checked');
				settings.enable_prefixes = enablePrefixes ? '1' : '0';
				var prefixes = [];
				if (enablePrefixes) {
					$('#jt-prefixes-list .jt-prefix-row').each(function() {
						var name = $(this).find('.jt-prefix-name').val().trim();
						var color = $(this).find('.jt-prefix-color').val();
						if (name) {
							prefixes.push({ name: name, color: color });
						}
					});
				}
				settings.prefixes = prefixes;

				// BuddyPress group linking.
				var $bpGroup = $('#ss-bp-group');
				if ($bpGroup.length) {
					settings.bp_group_id = $bpGroup.val() || '';
				}

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				self.ajax('jetonomy_update_space', {
					id: spaceId,
					edit_token: $form.attr('data-edit-token') || '',
					settings: JSON.stringify(settings)
				}).done(function(res) {
					if (res.success) {
						// The form stays open, so its next save is checked against what was just saved.
						$form.attr('data-edit-token', res.data.edit_token || '');
						self.toast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});

			// ── Space Members ──

			// User search for adding members
			var searchTimeout;
			$(document).on('input', '#member-search', function() {
				var $input = $(this);
				var query = $input.val().trim();
				clearTimeout(searchTimeout);

				if (query.length < 2) {
					$('#member-search-results').hide();
					return;
				}

				searchTimeout = setTimeout(function() {
					self.ajax('jetonomy_search_users', { search: query }).done(function(res) {
						if (res.success && res.data.users.length) {
							// Built with attr()/text(), never string concatenation: a
							// member's display name is free text and may contain quotes,
							// which .text().html() does not escape inside an attribute.
							var $results = $('#member-search-results').empty();
							$.each(res.data.users, function(i, user) {
								$('<div class="jetonomy-search-item"></div>')
									.attr({ 'data-user-id': user.id, 'data-name': user.display_name })
									.append($('<img alt="">').attr('src', user.avatar))
									.append($('<span></span>').text(user.display_name + ' (' + user.user_login + ')'))
									.appendTo($results);
							});
							$results.show();
						} else {
							$('#member-search-results').hide();
						}
					});
				}, 300);
			});

			// Select user from search results
			$(document).on('click', '.jetonomy-search-item', function() {
				var userId = $(this).data('user-id');
				var name = $(this).data('name');
				$('#member-user-id').val(userId);
				$('#member-search').val(name);
				$('#member-search-results').hide();
			});

			// Hide search results on click outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('#member-search, #member-search-results').length) {
					$('#member-search-results').hide();
				}
			});

			// Add member
			$(document).on('click', '#jetonomy-add-member', function() {
				var $btn = $(this);
				var spaceId = $btn.data('space-id');
				var userId = $('#member-user-id').val();
				var role = $('#member-role').val();

				if (!userId) {
					$('#member-search').focus();
					return;
				}

				$btn.prop('disabled', true);

				self.ajax('jetonomy_add_space_member', {
					space_id: spaceId,
					user_id: userId,
					role: role
				}).done(function(res) {
					if (res.success) {
						self.reloadWithToast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Change member role. Remember the saved value so a refused change
			// (last admin) snaps back instead of showing a role that did not save.
			$(document).on('focus', '.jetonomy-change-member-role', function() {
				$(this).data('saved', $(this).val());
			});
			$(document).on('change', '.jetonomy-change-member-role', function() {
				var $select = $(this);
				var spaceId = $select.data('space-id');
				var userId = $select.data('user-id');
				var role = $select.val();

				self.ajax('jetonomy_change_member_role', {
					space_id: spaceId,
					user_id: userId,
					role: role
				}).done(function(res) {
					if (res.success) {
						$select.data('saved', role);
						self.toast(res.data.message);
					} else {
						if (undefined !== $select.data('saved')) {
							$select.val($select.data('saved'));
						}
						self.toast(res.data || self.i18n.error, 'error');
					}
				});
			});

			// Remove member
			$(document).on('click', '.jetonomy-remove-member', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var spaceId = $btn.data('space-id');
				var userId = $btn.data('user-id');

				self.confirmAsync(self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_remove_space_member', {
						space_id: spaceId,
						user_id: userId
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() { $(this).remove(); });
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					});
				});
			});

			// ── Access Rules ──

			// Build adapter-specific rule type options and autocomplete.
			(function() {
				var adapters = (window.jetonomyAdmin && window.jetonomyAdmin.membershipAdapters) || [];
				var adapterMap = {};
				var $ruleType = $('#rule-type');

				// Inject adapter-specific options into the rule type dropdown.
				for (var i = 0; i < adapters.length; i++) {
					var a = adapters[i];
					adapterMap[a.id] = a;
					$ruleType.append('<option value="membership:' + a.id + '">' + a.label + '</option>');
				}

				var $wrap = $('#rule-value-membership-wrap');
				var $input = $('#rule-value-membership-search');
				var $hidden = $('#rule-value-membership');
				var $results = $('#rule-value-membership-results');
				var activeLevels = [];
				var activeAdapter = null;
				var searchTimer = null;
				var searchSeq = 0;

				// remote: rows the server returned for a typed search (adapters whose
				// preload is capped), so levels past the cap stay selectable.
				function renderResults(query, remote) {
					$results.empty();
					// An empty query lists the first levels, so an owner can
					// see what is on offer before knowing what to type.
					var q = (query || '').toLowerCase();
					var matches = (q && !remote) ? activeLevels.filter(function(l) { return l.label.toLowerCase().indexOf(q) > -1; }) : (remote || activeLevels);

					if (!matches.length) {
						$results.append($('<div class="jetonomy-ac-empty"/>').text(__('No matches', 'jetonomy')));
						$results.show();
						return;
					}

					// Group by the optional 'kind'. Adapters that have not
					// adopted it render exactly as before - one flat list.
					var grouped = matches.some(function(l) { return !!l.kind; });

					if (grouped) {
						// Order kinds by first appearance in the adapter's own
						// list, not alphabetically, so the adapter decides which
						// kind an owner is most likely to want first. Sort BEFORE
						// the cap, otherwise a kind can be split across the
						// boundary and appear twice.
						var kindOrder = {};
						activeLevels.forEach(function(l) {
							if (l.kind && !(l.kind in kindOrder)) {
								kindOrder[l.kind] = Object.keys(kindOrder).length;
							}
						});
						// Rows with no kind sort LAST, never between two headed
						// groups - sitting under someone else's heading with no
						// heading of their own reads as belonging to it. Only
						// reachable on an adapter that half-adopted 'kind'.
						function rank(l) { return l.kind ? kindOrder[l.kind] : Number.MAX_SAFE_INTEGER; }
						matches = matches.slice().sort(function(a, b) { return rank(a) - rank(b); });
					}

					// The cap is on the LIST, never per group: capping per group
					// would make the "N more" count a lie and could hide a whole
					// kind without saying so.
					var shown = matches.slice(0, 20);
					var lastKind = null;

					shown.forEach(function(level) {
						if (grouped) {
							var kind = level.kind || '';
							if (kind !== lastKind) {
								lastKind = kind;
								if (kind) {
									var $head = $('<div class="jetonomy-ac-group"/>');
									$head.append($('<span class="jetonomy-ac-group__kind"/>').text(kind));
									if (level.note) {
										$head.append($('<span class="jetonomy-ac-group__note"/>').text(level.note));
									}
									$results.append($head);
								}
							}
						}
						$results.append(
							$('<div class="jetonomy-ac-item"/>').attr('data-id', level.id).text(level.label)
						);
					});

					if (matches.length > shown.length) {
						var hidden = matches.length - shown.length;
						$results.append($('<div class="jetonomy-ac-empty"/>').text(
							/* translators: %d: number of matching levels not shown in the list. */
							sprintf(_n('%d more. Refine your search to see it.', '%d more. Refine your search to see them.', hidden, 'jetonomy'), hidden)
						));
					}
					$results.show();
				}

				$input.on('input', function() {
					$hidden.val('');
					var q = $(this).val();
					clearTimeout(searchTimer);
					// Filter the preload at once; a searchable adapter then swaps in
					// the server's answer, which also covers levels past the cap.
					renderResults(q);
					var seq = ++searchSeq;
					if (!q || !activeAdapter || !activeAdapter.searchable) {
						return;
					}
					searchTimer = setTimeout(function() {
						self.ajax('jetonomy_search_membership_levels', { adapter: activeAdapter.id, q: q }).done(function(res) {
							// Drop answers to an older keystroke.
							if (seq === searchSeq && res && res.success) {
								renderResults(q, res.data || []);
							}
						}).fail(function() {
							if (seq === searchSeq) {
								renderResults(q);
							}
						});
					}, 250);
				});
				$input.on('focus click', function() {
					renderResults($(this).val());
				});

				$results.on('click', '.jetonomy-ac-item', function() {
					$input.val($(this).text());
					$hidden.val($(this).data('id'));
					$results.hide();
					// Setting .val() fires no input event, so refresh the hint
					// or it keeps the typed search text instead of the pick.
					renderRulePreview();
				});

				$(document).on('click', function(e) {
					if (!$(e.target).closest('#rule-value-membership-wrap').length) {
						$results.hide();
					}
				});

				// Toggle between text input and adapter autocomplete.
				$(document).on('change', '#rule-type', function() {
					var val = $(this).val();
					var isAdapter = val.indexOf('membership:') === 0;
					// 'everyone' and 'logged_in' match on the type alone -
					// AccessRule ignores rule_value for both, and the handler
					// stores null. Showing a value box for them invited an
					// owner to type something that was silently discarded.
					var takesValue = !isAdapter && val !== 'everyone' && val !== 'logged_in';

					$('#rule-value').toggle(takesValue).val('');
					if (takesValue) {
						var placeholders = (self.i18n && self.i18n.rulePreview && self.i18n.rulePreview.typePlaceholders) || {};
						$('#rule-value').attr('placeholder', placeholders[val] || '');
					}
					$wrap.toggle(isAdapter);
					$input.val('');
					$hidden.val('');
					$results.hide();

					if (isAdapter) {
						var adapterId = val.replace('membership:', '');
						activeAdapter = adapterMap[adapterId] || null;
						activeLevels = activeAdapter ? activeAdapter.levels : [];
						$input.attr('placeholder', adapters.filter(function(a) { return a.id === adapterId; })[0].label + '...');
					}
					renderRulePreview();
				});

				/**
				 * Plain-English sentence for the rule being composed.
				 *
				 * The two trailing selects are the confusing part of this form:
				 * "Grants" is what the person may DO, "Space Role" is what they
				 * are RECORDED as, and nothing at the point of use said so — or
				 * warned that the pair can contradict itself (Read + Admin reads
				 * as "look but don't touch, and also run the place"). Rather than
				 * explain two dials in the abstract, describe the combination
				 * the owner has actually picked, and flag it when it disagrees.
				 */
				function renderRulePreview() {
					var $out = $('[data-jt-rule-preview]');
					if (!$out.length) { return; }

					var i18n     = (self.i18n && self.i18n.rulePreview) || {};
					var typeSel  = $('#rule-type option:selected').text();
					var grants   = $('#rule-grants').val();
					var role     = $('#rule-space-role').val();
					// Roster role is derived now, so its label comes from the
					// dictionary rather than a select the owner can see.
					var roleTxt  = (i18n.roles && i18n.roles[role]) || role;
					var grantTxt = (i18n.grants && i18n.grants[grants]) || grants;

					var who = i18n.whoFallback;
					var val = $('#rule-value').is(':visible') ? $('#rule-value').val() : $('#rule-value-membership-search').val();
					if (val) { who = typeSel + ': ' + val; }

					var sentence = (i18n.sentence)
						.replace('%1$s', who)
						.replace('%2$s', grantTxt)
						.replace('%3$s', roleTxt);

					// No mismatch warning: the roster role is derived
					// from the access level, so the two cannot disagree.
					var warn = '';

					// The "who matches" half. Keyed off the raw type, so an
					// adapter option ("membership:learndash") falls through to
					// the generic membership note below rather than showing a
					// built-in type's sentence.
					var typeNotes  = i18n.typeNotes || {};
					var ruleTypeId = $('#rule-type').val();
					var note       = typeNotes[ruleTypeId] || '';
					if (!note && ruleTypeId && ruleTypeId.indexOf('membership:') === 0 && i18n.membershipNote) {
						var adapterId2 = ruleTypeId.replace('membership:', '');
						var adapterRow = adapters.filter(function(a) { return a.id === adapterId2; })[0];
						if (adapterRow) {
							note = i18n.membershipNote.replace('%s', adapterRow.label);
						}
					}

					$out.html('').append($('<span/>').text(sentence));
					if (warn) {
						$out.append($('<span class="jt-rule-preview__warn"/>').text(' ' + warn));
					}
					if (note) {
						$out.append($('<span class="jt-rule-preview__note"/>').text(note));
					}
				}

				// Roster role is derived, never chosen: read -> viewer,
				// participate and full -> member (a rule never makes a
				// moderator). Matches AccessRule::cap_space_role() on the
				// server, which is the authority - this only keeps the hidden
				// field and the preview honest.
				function syncDerivedRole() {
					var map = { read: 'viewer', participate: 'member', full: 'member' };
					$('#rule-space-role').val(map[$('#rule-grants').val()] || 'viewer');
				}

				$(document).on('change', '#rule-grants', syncDerivedRole);
				syncDerivedRole();

				$(document).on('change', '#rule-grants, #rule-space-role', renderRulePreview);
				$(document).on('input', '#rule-value, #rule-value-membership-search', renderRulePreview);
				// Apply the type rules to whatever is selected on load, not just
				// on the first change. The form opens on 'Everyone', which takes
				// no value, so without this the owner is shown an empty value box
				// for a rule that ignores it.
				$ruleType.trigger('change');
				renderRulePreview();
			})();

			// Add rule
			$(document).on('click', '#jetonomy-add-rule', function() {
				var $btn = $(this);
				var spaceId = $btn.data('space-id');
				var ruleTypeVal = $('#rule-type').val();
				var isAdapter = ruleTypeVal.indexOf('membership:') === 0;
				var ruleType = isAdapter ? 'membership' : ruleTypeVal;
				var ruleValue = isAdapter ? $('#rule-value-membership').val() : $('#rule-value').val();

				$btn.prop('disabled', true);

				self.ajax('jetonomy_add_access_rule', {
					space_id: spaceId,
					rule_type: ruleType,
					rule_value: ruleValue,
					grants: $('#rule-grants').val(),
					space_role: $('#rule-space-role').val(),
					priority: $('#rule-priority').val()
				}).done(function(res) {
					if (res.success) {
						self.reloadWithToast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Sync existing memberships for a rule
			$(document).on('click', '.jetonomy-sync-rule', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text(self.i18n.syncing);

				self.ajax('jetonomy_sync_access_rule', {
					space_id: $btn.data('space-id'),
					rule_value: $btn.data('value'),
					// The server reads the rule by id and derives the roster role
					// from its grants.
					rule_id: $btn.data('id')
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$btn.text((self.i18n.syncedFormat).replace('%d', res.data.synced));
					} else {
						self.toast(res.data || self.i18n.error, 'error');
						$btn.text(self.i18n.sync);
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
					$btn.text(self.i18n.sync);
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Delete rule
			$(document).on('click', '.jetonomy-delete-rule', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				self.confirmAsync(self.i18n.confirmDelete, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_delete_access_rule', { id: id }).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() { $(this).remove(); });
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					});
				});
			});

			// ── Join Requests ──

			// Approve join request
			$(document).on('click', '.jetonomy-approve-join-request', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');
				var spaceId = $btn.data('space-id');

				$btn.prop('disabled', true);

				self.ajax('jetonomy_approve_join_request', {
					id: id,
					space_id: spaceId
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.fadeOut(300, function() { $(this).remove(); });
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Deny join request
			$(document).on('click', '.jetonomy-deny-join-request', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				$btn.prop('disabled', true);

				self.ajax('jetonomy_deny_join_request', {
					id: id
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.fadeOut(300, function() { $(this).remove(); });
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Invite Links
		// ═══════════════════════════════════════════════════════════

		bindInviteLinks: function() {
			var self = this;
			var $table = $('#jetonomy-invites-table');
			if (!$table.length) {
				return;
			}
			var spaceId = $table.data('space-id');

			// Build a single <tr> for an invite row.
			function rowHtml(invite) {
				var i18n = self.i18n;
				var uses = invite.max_uses > 0
					? (invite.used_count + ' / ' + invite.max_uses)
					: (invite.used_count + ' / ' + (i18n.inviteUnlimited));
				var expires = invite.expires_at ? (invite.expires_label || invite.expires_at) : (i18n.inviteNever);
				if (!invite.is_valid) {
					expires = (i18n.inviteExpired);
				}
				// These rows are injected after the shell renders, so they must
				// carry the same core small-screen contract jetonomy_admin_table()
				// emits server-side (column-primary + toggle-row on the primary
				// cell, data-colname everywhere). Without it the responsive CSS
				// has nothing to collapse and the row stays a wide strip.
				var $tr = $('<tr>').attr('data-invite-id', invite.id);
				$('<td>', { 'class': 'column-link column-primary', 'data-colname': i18n.inviteLink })
					.append($('<code>').text(invite.invite_url))
					.append($('<button>', {
						type: 'button',
						'class': 'toggle-row',
						'aria-expanded': 'false'
					}).append($('<span>', { 'class': 'screen-reader-text' }).text(i18n.showMoreDetails)))
					.appendTo($tr);
				$('<td>', { 'class': 'column-uses', 'data-colname': i18n.inviteUses }).text(uses).appendTo($tr);
				$('<td>', { 'class': 'column-expires', 'data-colname': i18n.inviteExpires }).text(expires).appendTo($tr);
				var $actions = $('<td>', { 'class': 'column-actions', 'data-colname': i18n.actions });
				$('<button>', { type: 'button', 'class': 'button button-small jetonomy-copy-invite' })
					.attr('data-url', invite.invite_url)
					.text(i18n.copy)
					.appendTo($actions);
				$('<button>', { type: 'button', 'class': 'button button-small button-link-delete jetonomy-revoke-invite' })
					.attr('data-id', invite.id)
					.text(' ' + (i18n.revoke))
					.appendTo($actions);
				$actions.appendTo($tr);
				return $tr;
			}

			// Load existing links for this space on tab render, one page at a
			// time: a space with hundreds of links must not arrive in one dump.
			function loadInvites(page) {
				self.ajax('jetonomy_list_invites', { space_id: spaceId, page: page }).done(function(res) {
					$('.jetonomy-invites-more').remove();
					if (!res.success || !res.data || !res.data.invites || !res.data.invites.length) {
						return;
					}
					var $tbody = $table.find('tbody');
					$tbody.find('.jetonomy-empty-row').remove();
					res.data.invites.forEach(function(invite) {
						$tbody.append(rowHtml(invite));
					});
					if (res.data.has_more) {
						$('<p>', { 'class': 'jetonomy-invites-more' })
							.text(sprintf(
								/* translators: 1: invite links shown, 2: all invite links on the space. */
								__( 'Showing %1$s of %2$s invite links.', 'jetonomy' ),
								$tbody.find('tr[data-invite-id]').length,
								res.data.total
							) + ' ')
							.append($('<button>', { type: 'button', 'class': 'button' }).text(self.i18n.inviteLoadMore).on('click', function() {
								loadInvites(page + 1);
							}))
							.insertAfter($table.parent());
					}
				});
			}
			loadInvites(1);

			// Copy a link to the clipboard.
			function copyToClipboard(text) {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						self.toast(self.i18n.inviteCopied);
					}).catch(function() {
						self.toast(self.i18n.error, 'error');
					});
					return;
				}
				var $tmp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
				$tmp[0].select();
				try {
					document.execCommand('copy');
					self.toast(self.i18n.inviteCopied);
				} catch (e) {
					self.toast(self.i18n.error, 'error');
				}
				$tmp.remove();
			}

			// Generate a new invite link.
			$(document).on('click', '#jetonomy-generate-invite', function() {
				var $btn = $(this);
				var maxUses = parseInt($('#invite-max-uses').val(), 10) || 0;
				var expiresAt = $('#invite-expires-at').val() || '';

				$btn.prop('disabled', true);

				self.ajax('jetonomy_generate_invite', {
					space_id: spaceId,
					max_uses: maxUses,
					expires_at: expiresAt
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message || self.i18n.saved);
						var $tbody = $table.find('tbody');
						$tbody.find('.jetonomy-empty-row').remove();
						$tbody.prepend(rowHtml({
							id: res.data.id,
							invite_url: res.data.invite_url,
							max_uses: res.data.max_uses,
							used_count: 0,
							expires_at: res.data.expires_at,
							expires_label: res.data.expires_label,
							is_valid: true
						}));
						copyToClipboard(res.data.invite_url);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});

			// Copy an existing link.
			$(document).on('click', '.jetonomy-copy-invite', function() {
				copyToClipboard($(this).data('url'));
			});

			// Revoke a link.
			$(document).on('click', '.jetonomy-revoke-invite', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var id = $btn.data('id');

				self.confirmAsync(self.i18n.inviteRevokeConfirm, { danger: true }).then(function(ok) {
					if (!ok) return;
					self.ajax('jetonomy_revoke_invite', {
						space_id: spaceId,
						id: id
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.fadeOut(300, function() {
								$(this).remove();
								if (!$table.find('tbody tr').length) {
									self.reloadWithToast(res.data.message);
								}
							});
						} else {
							self.toast(res.data || self.i18n.error, 'error');
						}
					});
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Moderation
		// ═══════════════════════════════════════════════════════════

		bindModerationActions: function() {
			var self = this;

			// Approve / Spam / Trash
			$(document).on('click', '.jetonomy-moderate-btn', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var action = $btn.data('action');
				var objectType = $btn.data('type');
				var objectId = $btn.data('id');

				var ajaxAction;
				var confirmMsg = '';
				switch (action) {
					case 'approve':
						ajaxAction = 'jetonomy_approve_content';
						break;
					case 'spam':
						ajaxAction = 'jetonomy_spam_content';
						confirmMsg = self.i18n.confirmSpam;
						break;
					case 'trash':
						ajaxAction = 'jetonomy_trash_content';
						confirmMsg = self.i18n.confirmTrash;
						break;
					default:
						return;
				}

				// Spam / Trash are destructive — they pull content from the
				// community. Confirm before firing (Approve is safe, no prompt).
				var run = function() {
					$btn.prop('disabled', true);
					$row.find('.jetonomy-moderate-btn').prop('disabled', true);

					self.ajax(ajaxAction, {
						object_type: objectType,
						object_id: objectId
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							$row.addClass('jetonomy-moderated');
							setTimeout(function() {
								$row.fadeOut(300, function() { $(this).remove(); });
							}, 500);
						} else {
							self.toast(res.data || self.i18n.error, 'error');
							$row.find('.jetonomy-moderate-btn').prop('disabled', false);
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
						$row.find('.jetonomy-moderate-btn').prop('disabled', false);
					});
				};

				if ('' !== confirmMsg) {
					self.confirmAsync(confirmMsg, { danger: true }).then(function(ok) {
						if (ok) { run(); }
					});
				} else {
					run();
				}
			});

			// Resolve Flag
			$(document).on('click', '.jetonomy-resolve-flag', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var flagId = $btn.data('flag-id');
				var resolution = $btn.data('resolution');

				$btn.prop('disabled', true);
				$row.find('.jetonomy-resolve-flag').prop('disabled', true);

				self.ajax('jetonomy_resolve_flag', {
					flag_id: flagId,
					resolution: resolution
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$row.addClass('jetonomy-moderated');
						setTimeout(function() {
							$row.fadeOut(300, function() { $(this).remove(); });
						}, 500);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
						$row.find('.jetonomy-resolve-flag').prop('disabled', false);
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
					$row.find('.jetonomy-resolve-flag').prop('disabled', false);
				});
			});

			// Unban User
			$(document).on('click', '.jetonomy-unban-user', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var restrictionId = $btn.data('restriction-id');

				// Neutral on purpose (owner decision 2026-10-15): lifting gives
				// access back and destroys nothing, and red stays reserved for
				// destructive actions like Ban and Delete. Same verb as the
				// community-side Lift control.
				self.confirmAsync(self.i18n.confirmUnban, { title: self.i18n.unban, confirmLabel: self.i18n.unban }).then(function(ok) {
					if (!ok) return;
					$btn.prop('disabled', true);

					self.ajax('jetonomy_unban_user', {
						restriction_id: restrictionId
					}).done(function(res) {
						if (res.success) {
							self.toast(res.data.message);
							// On Users page: restore Ban link. On Moderation page: remove row.
							if ($row.find('.ban').length) {
								$row.find('.jetonomy-restriction-label').remove();
								// The name is member-chosen text: set it as an attribute, never splice it into HTML.
								$row.find('.ban').empty().append(
									$('<a href="#" class="jetonomy-ban-trigger"></a>')
										.attr({ 'data-user-id': $row.data('user-id'), 'data-username': $row.find('strong').first().text() })
										.text(self.i18n.ban),
									document.createTextNode(' | ')
								);
								$row.find('.silence, .ban-ip').prop('hidden', false);
							} else {
								$row.fadeOut(300, function() { $(this).remove(); });
							}
						} else {
							self.toast(res.data || self.i18n.error, 'error');
							$btn.prop('disabled', false);
						}
					}).fail(function() {
						self.toast(self.i18n.error, 'error');
						$btn.prop('disabled', false);
					});
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Users
		// ═══════════════════════════════════════════════════════════

		bindUserActions: function() {
			var self = this;

			// Change Trust Level - Show Dropdown
			$(document).on('click', '.jetonomy-change-trust-trigger', function(e) {
				e.preventDefault();
				var $link = $(this);
				var userId = $link.data('user-id');
				var current = $link.data('current');

				var $dropdown = $('#jetonomy-trust-dropdown');
				$('#trust-user-id').val(userId);
				$('#trust-level-select').val(current);

				// Position near the link
				var offset = $link.offset();
				$dropdown.css({
					top: offset.top + $link.outerHeight() + 4,
					left: offset.left
				}).show();
			});

			// Save Trust Level
			$(document).on('click', '#jetonomy-save-trust', function() {
				var $btn = $(this);
				var userId = $('#trust-user-id').val();
				var trustLevel = $('#trust-level-select').val();

				$btn.prop('disabled', true);

				self.ajax('jetonomy_change_trust_level', {
					user_id: userId,
					trust_level: trustLevel
				}).done(function(res) {
					if (res.success) {
						// Reload for a clean label update; the toast follows the reload.
						self.reloadWithToast(res.data.message);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					$('#jetonomy-trust-dropdown').hide();
				});
			});

			// Cancel trust dropdown
			$(document).on('click', '.jetonomy-dropdown-cancel', function() {
				$(this).closest('.jetonomy-dropdown').hide();
			});

			// Close dropdown on outside click
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.jetonomy-dropdown, .jetonomy-change-trust-trigger').length) {
					$('.jetonomy-dropdown').hide();
				}
			});

			// One modal serves Ban, Silence and IP ban; its title and confirm button
			// follow the chosen type so the operator never confirms the wrong action.
			var banActions = {
				global_ban: __( 'Ban User', 'jetonomy' ),
				silence:    __( 'Silence User', 'jetonomy' ),
				ip_ban:     __( 'Ban IP address', 'jetonomy' )
			};
			var syncBanModal = function() {
				var type = $('#ban-type').val();
				var label = banActions[ type ] || banActions.global_ban;
				$('#ban-modal-title').text(label);
				$('#jetonomy-confirm-ban').text(label);
				$('#ban-ip-row').prop('hidden', 'ip_ban' !== type);
				// An IP ban keeps the address in the reason column, so a typed reason would be dropped.
				$('#ban-reason-row').prop('hidden', 'ip_ban' === type);
			};
			var openBanModal = function($trigger, type, duration, labelFormat) {
				var username = $trigger.data('username') || $trigger.closest('tr').find('.column-username strong').text();
				$('#ban-user-id').val($trigger.data('user-id'));
				$('#ban-user-label').text(sprintf(labelFormat, username));
				$('#ban-type').val(type);
				$('#ban-reason').val('');
				$('#ban-ip').val('');
				$('#ban-duration').val(duration);
				syncBanModal();
				$('#jetonomy-ban-modal').show();
			};
			$(document).on('change', '#ban-type', syncBanModal);

			// Ban User - Show Modal
			$(document).on('click', '.jetonomy-ban-trigger', function(e) {
				e.preventDefault();
				/* translators: %s: username of the member being banned. */
				openBanModal($(this), 'global_ban', 'permanent', __('Banning: %s', 'jetonomy'));
			});

			// Silence User - Show Modal with Silence preselected
			$(document).on('click', '.jetonomy-silence-trigger', function(e) {
				e.preventDefault();
				/* translators: %s: username of the member being silenced. */
				openBanModal($(this), 'silence', '7d', __('Silencing: %s', 'jetonomy'));
			});

			// Ban IP - Show Modal with IP ban preselected
			$(document).on('click', '.jetonomy-ban-ip-trigger', function(e) {
				e.preventDefault();
				/* translators: %s: username of the member whose IP address is being banned. */
				openBanModal($(this), 'ip_ban', 'permanent', __('Banning an IP address used by: %s', 'jetonomy'));
				$('#ban-ip').trigger('focus');
			});

			// Confirm Ban
			$(document).on('click', '#jetonomy-confirm-ban', function() {
				var $btn = $(this);
				var userId = $('#ban-user-id').val();
				if (!userId) return;

				$btn.prop('disabled', true);
				self.showSpinner($btn);

				var banType = $('#ban-type').val();
				self.ajax('jetonomy_ban_user', {
					user_id: userId,
					type: banType,
					reason: $('#ban-reason').val(),
					ip: $('#ban-ip').val(),
					duration: $('#ban-duration').val()
				}).done(function(res) {
					if (res.success) {
						self.toast(res.data.message);
						$('#jetonomy-ban-modal').hide();
						// An IP ban is not the member's own standing; it is lifted from Moderation > Banned Users.
						if ('ip_ban' === banType) {
							return;
						}
						// Toggle row action: Ban → Unban, and name the restriction as the server render does.
						var $row = $('tr[data-user-id="' + userId + '"]');
						$row.find('.jetonomy-restriction-label').remove();
						$row.find('.column-username strong').first().after(
							' ',
							$('<span class="jetonomy-badge jetonomy-badge--ban jetonomy-restriction-label"></span>')
								.text('silence' === banType ? __('Silenced', 'jetonomy') : __('Banned', 'jetonomy'))
						);
						$row.find('.ban').html(
							'<a href="#" class="jetonomy-unban-user" data-restriction-id="' + res.data.restriction_id + '">' +
							self.i18n.unban +
							'</a>'
						);
						$row.find('.silence, .ban-ip').prop('hidden', true);
					} else {
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					self.toast(self.i18n.error, 'error');
				}).always(function() {
					$btn.prop('disabled', false);
					self.hideSpinner($btn);
				});
			});
		},

		// ═══════════════════════════════════════════════════════════
		//  Import
		// ═══════════════════════════════════════════════════════════

		bindImport: function() {
			var self = this;

			// Fresh import
			$(document).on('click', '.jetonomy-import-btn', function() {
				self.startImport($(this).data('source'), 'forums', 0, true);
			});

			// Resume interrupted import — continues prior state, so NOT a new run.
			$(document).on('click', '.jetonomy-import-resume-btn', function() {
				var $btn = $(this);
				self.startImport($btn.data('source'), $btn.data('phase'), parseInt($btn.data('offset'), 10), false);
			});

			// Start over — overwrite resume state then start fresh from beginning
			$(document).on('click', '.jetonomy-import-restart-btn', function() {
				var $btn = $(this);
				self.confirmAsync(
					self.i18n.importRestartConfirm,
					{ danger: true, title: self.i18n.importRestartTitle }
				).then(function(ok) {
					if (!ok) return;
					self.startImport($btn.data('source'), 'forums', 0, true);
				});
			});
		},

		startImport: function(source, startPhase, startOffset, isNewRun) {
			var self = this;
			var card = document.getElementById('import-source-' + source);
			if (!card) return;

			var progress     = card.querySelector('.jetonomy-import-progress');
			var progressFill = progress.querySelector('.jetonomy-progress-bar__fill');
			var statusText   = progress.querySelector('.jetonomy-import-status-text');
			var statusPct    = progress.querySelector('.jetonomy-import-status-percent');
			var results      = card.querySelector('.jetonomy-import-results');
			var actionDiv    = card.querySelector('.jetonomy-import-action');
			var steps        = progress.querySelectorAll('.jetonomy-step');

			// Hide action buttons, show progress UI
			actionDiv.style.display  = 'none';
			progress.style.display   = 'block';
			results.style.display    = 'none';
			progress.classList.remove('jetonomy-import-progress--done');
			progressFill.style.width = '0%';
			statusPct.textContent    = '0%';

			function updateStepIndicator(phase) {
				// Sources without a Members step count it under Forums.
				if (phase === 'members' && !progress.querySelector('.jetonomy-step[data-step="members"]')) { phase = 'forums'; }
				var found = false;
				// Iterate in reverse so steps before the active one get marked done
				for (var i = steps.length - 1; i >= 0; i--) {
					var s = steps[i];
					s.classList.remove('jetonomy-step--active', 'jetonomy-step--done');
					if (s.dataset.step === phase) {
						s.classList.add('jetonomy-step--active');
						found = true;
					} else if (found) {
						s.classList.add('jetonomy-step--done');
					}
				}
			}

			function buildCompleteNotice(processed, skipped, summary) {
				skipped = parseInt(skipped, 10) || 0;
				processed = parseInt(processed, 10) || 0;
				var notice = document.createElement('div');
				// A partial success is a warning, not a clean success — the site owner
				// needs to know some files did not come across rather than seeing a
				// green tick and assuming everything migrated.
				notice.className = skipped > 0 ? 'notice notice-warning' : 'notice notice-success';
				var p = document.createElement('p');
				var strong = document.createElement('strong');
				strong.textContent = (Jetonomy.i18n.importDone) + ' ';
				p.appendChild(strong);
				p.appendChild(document.createTextNode(
					/* translators: %d: number of records imported. */
					sprintf(_n('%d record imported successfully.', '%d records imported successfully.', processed, 'jetonomy'), processed) + ' '
				));
				// What was not created and why: already imported by an earlier run
				// (or "0 records imported" reads as a failure), and rows left out
				// because their topic or forum was not imported (or the source
				// count does not add up). Server-worded: Importer::describe_tally().
				if (summary) {
					p.appendChild(document.createTextNode(summary + ' '));
				}
				if (skipped > 0) {
					var warn = document.createElement('strong');
					warn.textContent = sprintf(
						/* translators: %d: number of attachment files that could not be recovered. */
						_n('%d file could not be recovered and was left linked in the original post text.', '%d files could not be recovered and were left linked in the original post text.', skipped, 'jetonomy'),
						skipped
					) + ' ';
					p.appendChild(warn);
				}
				var link = document.createElement('a');
				link.href = '';
				link.textContent = Jetonomy.i18n.reloadPage;
				// Split around the placeholder so the link can sit anywhere the
				// translation puts it.
				var reloadParts = (
					/* translators: %s: the "Reload page" link. */
					__('%s to see updated status.', 'jetonomy')
				).split('%s');
				p.appendChild(document.createTextNode(reloadParts[0]));
				p.appendChild(link);
				p.appendChild(document.createTextNode(reloadParts.slice(1).join('%s')));
				notice.appendChild(p);
				return notice;
			}

			// Only the very first batch of a fresh/restart run signals new_run so the
			// server clears prior state once — recursive continuations must not, or
			// they would wipe the id_map the run depends on (and wpForo's per-board
			// hand-off also arrives as forums/0).
			function runBatch(phase, offset, newRun) {
				updateStepIndicator(phase);

				var data = new FormData();
				data.append('action',     'jetonomy_import_batch');
				data.append('nonce',      self.nonce);
				data.append('source',     source);
				data.append('phase',      phase);
				data.append('offset',     offset);
				data.append('batch_size', 500);
				data.append('new_run',    newRun ? 1 : 0);

				fetch(self.ajaxUrl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						if (!res.success) {
							var errFmt    = Jetonomy.i18n.importErrorFormat;
							var errDetail = res.data || (Jetonomy.i18n.importErrorUnknown);
							statusText.textContent  = errFmt.replace('%s', errDetail);
							actionDiv.style.display = 'block';
							return;
						}

						var d = res.data;
						progressFill.style.width = d.percent + '%';
						statusText.textContent   = d.message;
						statusPct.textContent    = d.percent + '%';

						if (!d.done) {
							runBatch(d.phase, d.offset, false);
						} else {
							// Mark complete
							progressFill.style.width = '100%';
							statusPct.textContent    = '100%';
							progress.classList.add('jetonomy-import-progress--done');
							statusText.textContent   = Jetonomy.i18n.importDone;

							steps.forEach(function(s) {
								s.classList.remove('jetonomy-step--active');
								s.classList.add('jetonomy-step--done');
							});

							results.style.display = 'block';
							while (results.firstChild) { results.removeChild(results.firstChild); }
							results.appendChild(buildCompleteNotice(d.imported, d.skipped, d.summary));

							// Only auto-reload a CLEAN import. The reload exists to reveal the
							// "Previously Imported" state, which is fine when there is nothing to read - but when
							// files were skipped a reload would pull the page out from under the one notice telling
							// you so. The skipped count and the per-item reasons are recorded durably in the import
							// history and rendered on this page (see admin/views/import.php), so nothing is lost
							// either way; this just avoids interrupting someone mid-sentence about their own data.
							if (!d.skipped) {
								setTimeout(function() { window.location.reload(); }, 3000);
							}
						}
					})
					.catch(function() {
						statusText.textContent  = Jetonomy.i18n.importConnectionLost;
						actionDiv.style.display = 'block';
					});
			}

			runBatch(startPhase, startOffset, !!isNewRun);
		},

		// ═══════════════════════════════════════════════════════════
		//  Settings
		// ═══════════════════════════════════════════════════════════

		bindSettings: function() {
			var self = this;

			// Test Email
			$(document).on('click', '#jetonomy-test-email', function() {
				var $btn = $(this);
				var $status = $('.jetonomy-test-email-status');

				$btn.prop('disabled', true);
				$status.text(self.i18n.saving);

				self.ajax('jetonomy_test_email').done(function(res) {
					if (res.success) {
						$status.text(res.data.message).removeClass('jt-admin-msg--error').addClass('jt-admin-msg--success');
						self.toast(res.data.message);
					} else {
						$status.text(res.data || self.i18n.error).removeClass('jt-admin-msg--success').addClass('jt-admin-msg--error');
						self.toast(res.data || self.i18n.error, 'error');
					}
				}).fail(function() {
					$status.text(self.i18n.error).removeClass('jt-admin-msg--success').addClass('jt-admin-msg--error');
				}).always(function() {
					$btn.prop('disabled', false);
				});
			});
		}
	};

	$(document).ready(function() {
		Jetonomy.init();
	});

	// Small-screen row expander: core's common.js toggles tr.is-expanded but
	// never manages aria-expanded, so screen readers heard a stateless
	// button. Delegated so it covers every
	// jetonomy_admin_table() on the page, including AJAX-refreshed rows.
	// requestAnimationFrame lets core's own toggle land first, then the
	// button mirrors the row's real state.
	//
	// Initial state: WP_List_Table screens (Activity, Revisions) print
	// core's OWN toggle markup, which ships without aria-expanded - stamp
	// it on load so the button is never stateless before first interaction
	// (QA wave-5).
	function stampToggleState(root) {
		(root || document).querySelectorAll('.jetonomy-admin .toggle-row:not([aria-expanded])').forEach(function (btn) {
			var tr = btn.closest('tr');
			btn.setAttribute('aria-expanded', tr && tr.classList.contains('is-expanded') ? 'true' : 'false');
		});
	}
	$(document).ready(function () { stampToggleState(); });
	$(document).ajaxComplete(function () { stampToggleState(); });

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.jetonomy-admin .toggle-row') : null;
		if (!btn) { return; }
		window.requestAnimationFrame(function () {
			var tr = btn.closest('tr');
			btn.setAttribute('aria-expanded', tr && tr.classList.contains('is-expanded') ? 'true' : 'false');
		});
	});

})(jQuery);

/**
 * Accessible-dialog enhancer.
 *
 * The admin's hand-rolled overlays (Edit Category, Ban, Pro Badge Award)
 * showed/hid a positioned div with none of the dialog contract. Rather
 * than rewrite each caller, a MutationObserver watches every overlay's
 * display state and applies the ONE primitive on open: role=dialog +
 * aria-modal + labelled title, initial focus (Close first), trapped Tab
 * order, Escape closes, opener focus restored on close. Callers keep
 * their own show()/hide() logic untouched.
 */
( function () {
	var active = null;

	function focusables( root ) {
		return Array.prototype.filter.call(
			root.querySelectorAll( 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' ),
			function ( el ) { return el.offsetParent !== null; }
		);
	}

	function onKey( e ) {
		if ( ! active ) { return; }
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			active.el.style.display = 'none'; // observer runs teardown()
			return;
		}
		if ( e.key !== 'Tab' ) { return; }
		var f = focusables( active.el );
		if ( ! f.length ) { e.preventDefault(); return; }
		var first = f[ 0 ], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}

	function boxOf( el ) {
		return el.querySelector( '.jetonomy-modal__content' ) ||
			( el.children.length === 1 ? el.children[ 0 ] : el.children[ el.children.length - 1 ] ) || el;
	}

	function enhance( el ) {
		var box = boxOf( el );
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		if ( ! box.getAttribute( 'aria-labelledby' ) ) {
			var h = box.querySelector( 'h1, h2, h3' );
			if ( h ) {
				if ( ! h.id ) { h.id = 'jt-dialog-title-' + Math.random().toString( 36 ).slice( 2, 8 ); }
				box.setAttribute( 'aria-labelledby', h.id );
			}
		}
		// Opener: prefer whatever the user actually activated. A dialog opened
		// from an async callback (the email preview waits on its AJAX) enhances
		// long after the click, by which point document.activeElement is <body>
		// - so Escape would restore focus to nothing.
		// lastActivated is the control the user pressed, tracked
		// below, and is only fallen back on when activeElement is not useful.
		var opener = document.activeElement;
		if ( ! opener || opener === document.body || opener === document.documentElement ) {
			opener = lastActivated;
		}
		active = { el: el, opener: opener };
		document.addEventListener( 'keydown', onKey, true );
		var target = el.querySelector( '.jetonomy-modal-close' ) || focusables( el )[ 0 ];
		if ( target ) { target.focus(); }
	}

	// The control the user last activated, used as a dialog's opener when the
	// dialog is created asynchronously and focus has already gone back to the
	// body. Capture phase so it is recorded even when a handler stops
	// propagation.
	var lastActivated = null;
	document.addEventListener( 'mousedown', function ( e ) {
		var el = e.target && e.target.closest ? e.target.closest( 'button, a[href], [role="button"], input[type="submit"], input[type="button"]' ) : null;
		if ( el ) { lastActivated = el; }
	}, true );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) { return; }
		var el = e.target && e.target.closest ? e.target.closest( 'button, a[href], [role="button"]' ) : null;
		if ( el ) { lastActivated = el; }
	}, true );

	function teardown( el ) {
		if ( ! active || active.el !== el ) { return; }
		var opener = active.opener;
		active = null;
		document.removeEventListener( 'keydown', onKey, true );
		if ( opener && opener.focus ) { opener.focus(); }
	}

	function watch( el ) {
		// jQuery's .show() clears the inline value to '' and lets the
		// stylesheet default apply, so open-ness must be read from the
		// COMPUTED display, never the style attribute.
		var wasOpen = getComputedStyle( el ).display !== 'none';
		new MutationObserver( function () {
			var isOpen = getComputedStyle( el ).display !== 'none';
			if ( isOpen && ! wasOpen ) { enhance( el ); }
			if ( ! isOpen && wasOpen ) { teardown( el ); }
			wasOpen = isOpen;
		} ).observe( el, { attributes: true, attributeFilter: [ 'style', 'class' ] } );
	}

	function init() {
		document.querySelectorAll( '.jetonomy-modal, #jetonomy-award-modal' ).forEach( watch );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
