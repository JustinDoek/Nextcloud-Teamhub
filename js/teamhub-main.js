/**
 * TeamHub Main Application
 * 
 * This is a simplified vanilla JavaScript version.
 * For production, consider using Vue.js 3 with @nextcloud/vue components
 */

(function() {
    'use strict';

    const TeamHubApp = {
        currentTeamId: null,
        currentView: 'msgstream',
        teams: [],
        
        init() {
            this.loadTeams();
            this.renderWelcome();
        },
        
        async loadTeams() {
            try {
                console.log('TeamHub: Starting to load teams...');
                const response = await fetch(OC.generateUrl('/apps/teamhub/api/v1/teams'));
                console.log('TeamHub: Response status:', response.status);
                console.log('TeamHub: Response headers:', response.headers);
                
                const data = await response.json();
                console.log('TeamHub: Received data:', data);
                console.log('TeamHub: Data type:', typeof data);
                console.log('TeamHub: Is array?', Array.isArray(data));
                
                // Check if response has error format
                if (data.error && data.teams) {
                    console.warn('TeamHub: API returned error:', data.error, data.message);
                    this.teams = data.teams; // Use the teams array from error response
                } else if (Array.isArray(data)) {
                    this.teams = data;
                } else {
                    console.error('TeamHub: Unexpected data format');
                    this.teams = [];
                }
                
                console.log('TeamHub: Teams set to:', this.teams);
                this.renderNavigation();
            } catch (error) {
                console.error('TeamHub: Error loading teams:', error);
                console.error('TeamHub: Error stack:', error.stack);
                this.teams = [];
                this.renderNavigation();
                this.showError('Failed to load teams');
            }
        },
        
        renderNavigation() {
            console.log('TeamHub: renderNavigation called');
            console.log('TeamHub: this.teams =', this.teams);
            console.log('TeamHub: typeof this.teams =', typeof this.teams);
            console.log('TeamHub: Array.isArray(this.teams) =', Array.isArray(this.teams));
            
            const nav = document.getElementById('teamhub-navigation');
            
            let html = `
                <div class="teamhub-nav-actions">
                    <button class="primary" id="teamhub-create-team-btn">Create Team</button>
                    <button class="secondary" id="teamhub-post-message-btn" ${!this.currentTeamId ? 'disabled' : ''}>Post Message</button>
                    <input type="text" class="teamhub-search" id="teamhub-search-input" placeholder="Search teams...">
                </div>
                <div class="teamhub-teams-header">Your Teams</div>
                <ul class="teamhub-team-list" id="teamhub-team-list">
            `;
            
            if (!this.teams || this.teams.length === 0) {
                console.log('TeamHub: No teams to display');
                html += '<li class="teamhub-empty">No teams yet</li>';
            } else {
                console.log('TeamHub: Rendering', this.teams.length, 'teams');
                this.teams.forEach(team => {
                    console.log('TeamHub: Rendering team:', team);
                    const isActive = this.currentTeamId === team.id ? 'active' : '';
                    const memberCount = team.members || 0;
                    html += `
                        <li class="teamhub-team-item ${isActive}" data-team-id="${team.id}">
                            <div class="teamhub-team-name">${this.escapeHtml(team.name)}</div>
                            <div class="teamhub-team-members">${memberCount} members</div>
                        </li>
                    `;
                });
            }
            
            html += '</ul>';
            nav.innerHTML = html;
            
            // Add event listeners after rendering
            this.attachNavigationEventListeners();
            
            console.log('TeamHub: Navigation rendered');
        },

        attachNavigationEventListeners() {
            // Create team button
            const createBtn = document.getElementById('teamhub-create-team-btn');
            if (createBtn) {
                createBtn.addEventListener('click', () => this.renderCreateTeam());
            }
            
            // Post message button
            const postBtn = document.getElementById('teamhub-post-message-btn');
            if (postBtn) {
                postBtn.addEventListener('click', () => this.renderPostMessage());
            }
            
            // Search input
            const searchInput = document.getElementById('teamhub-search-input');
            if (searchInput) {
                searchInput.addEventListener('keyup', (e) => this.filterTeams(e.target.value));
            }
            
            // Team items
            const teamList = document.getElementById('teamhub-team-list');
            if (teamList) {
                teamList.addEventListener('click', (e) => {
                    const teamItem = e.target.closest('.teamhub-team-item');
                    if (teamItem) {
                        const teamId = teamItem.dataset.teamId;
                        this.selectTeam(teamId);
                    }
                });
            }
        },
        
        async renderWelcome() {
            const main = document.getElementById('teamhub-main');
            
            main.innerHTML = `
                <div class="teamhub-welcome">
                    <h2>Welcome to TeamHub</h2>
                    <p>Your centralized hub for team collaboration and communication.</p>
                    <div class="teamhub-aggregated-messages">
                        <h3>Recent Messages</h3>
                        <div id="aggregated-messages-list" class="teamhub-loading">Loading messages...</div>
                    </div>
                </div>
            `;
            
            await this.loadAggregatedMessages();
        },
        
        async loadAggregatedMessages() {
            try {
                const response = await fetch(OC.generateUrl('/apps/teamhub/api/v1/messages/aggregated'));
                const messages = await response.json();
                
                const container = document.getElementById('aggregated-messages-list');
                
                if (messages.length === 0) {
                    container.innerHTML = '<div class="teamhub-empty">No recent messages</div>';
                    return;
                }
                
                let html = '<div class="teamhub-message-list">';
                messages.forEach(msg => {
                    const team = this.teams.find(t => t.id === msg.team_id);
                    const teamName = team ? team.name : 'Unknown Team';
                    const date = new Date(msg.created_at * 1000).toLocaleString();
                    
                    html += `
                        <div class="teamhub-message">
                            <div class="teamhub-message-header">
                                <span class="teamhub-message-author">${this.escapeHtml(msg.author_id)}</span>
                                <span class="teamhub-message-date">${date}</span>
                            </div>
                            <div class="teamhub-message-subject">${this.escapeHtml(msg.subject)}</div>
                            <div class="teamhub-message-body">${this.escapeHtml(msg.message.substring(0, 200))}${msg.message.length > 200 ? '...' : ''}</div>
                            <span class="teamhub-message-team-badge">${this.escapeHtml(teamName)}</span>
                        </div>
                    `;
                });
                html += '</div>';
                
                container.innerHTML = html;
            } catch (error) {
                console.error('Error loading messages:', error);
                document.getElementById('aggregated-messages-list').innerHTML = 
                    '<div class="teamhub-empty">Failed to load messages</div>';
            }
        },
        
        async selectTeam(teamId) {
            this.currentTeamId = teamId;
            this.currentView = 'msgstream';
            this.renderNavigation();
            await this.renderTeamView();
        },
        
        async renderTeamView() {
            const team = this.teams.find(t => t.id === this.currentTeamId);
            if (!team) return;

            const main = document.getElementById('teamhub-main');

            // Fetch resources and weblinks in parallel
            let resources = { talk: null, files: null, calendar: null, deck: null };
            let webLinks = [];
            try {
                const [resRes, linksRes] = await Promise.all([
                    fetch(OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/resources`)),
                    fetch(OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/links`))
                ]);
                if (resRes.ok) resources = await resRes.json();
                if (linksRes.ok) { const d = await linksRes.json(); if (Array.isArray(d)) webLinks = d; }
            } catch (e) { console.log('TeamHub: Error fetching menu data', e); }

            // Store resources on instance for use by tab renderers
            this.currentResources = resources;

            // --- BUILD MENU: app tabs first, then weblinks, then "+" ---
            let menuHtml = `<button class="teamhub-menu-item active" data-view="msgstream">💬 Messages</button>`;

            // App component tabs (open in content area, not new window)
            if (resources.talk && resources.talk.token) {
                menuHtml += `<button class="teamhub-menu-item" data-view="talk">💬 Chat</button>`;
            }
            if (resources.files && resources.files.path) {
                menuHtml += `<button class="teamhub-menu-item" data-view="files">📁 Files</button>`;
            }
            if (resources.calendar) {
                menuHtml += `<button class="teamhub-menu-item" data-view="calendar">📅 Calendar</button>`;
            }
            if (resources.deck && resources.deck.board_id) {
                menuHtml += `<button class="teamhub-menu-item" data-view="deck">📋 Deck</button>`;
            }

            // Web links (open in new tab)
            webLinks.forEach(link => {
                menuHtml += `<button class="teamhub-menu-item" data-link-url="${this.escapeHtml(link.url)}">${this.escapeHtml(link.title)}</button>`;
            });

            menuHtml += `<button class="teamhub-menu-item teamhub-menu-add" id="manage-links-btn">+</button>`;

            main.innerHTML = `
                <div class="teamhub-team-view">
                    <div class="teamhub-team-header">
                        <h2>${this.escapeHtml(team.name)}</h2>
                        <div class="teamhub-menu-bar">${menuHtml}</div>
                    </div>
                    <div class="teamhub-content-area">
                        <div id="team-content" class="teamhub-main-content"></div>
                        <div class="teamhub-sidebar">
                            <div class="teamhub-widget">
                                <h3>Team Info</h3>
                                <div class="teamhub-widget-content">
                                    <div class="teamhub-info-item">
                                        <strong>Description</strong>
                                        <p>${this.escapeHtml(team.description || 'No description')}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="teamhub-widget" id="calendar-widget" style="display:none;">
                                <div class="teamhub-widget-header">
                                    <h3>📅 Calendar</h3>
                                    <button class="teamhub-widget-action" id="open-calendar-btn">Open</button>
                                </div>
                                <div class="teamhub-widget-content" id="calendar-events">
                                    <div class="teamhub-loading-small">Loading events...</div>
                                </div>
                            </div>
                            <div class="teamhub-widget" id="deck-widget" style="display:none;">
                                <div class="teamhub-widget-header">
                                    <h3>📋 Deck</h3>
                                    <button class="teamhub-widget-action" id="open-deck-btn">Open</button>
                                </div>
                                <div class="teamhub-widget-content" id="deck-tasks">
                                    <div class="teamhub-loading-small">Loading tasks...</div>
                                </div>
                            </div>
                            <div class="teamhub-widget" id="meetings-widget" style="display:none;">
                                <div class="teamhub-widget-header">
                                    <h3>💬 Meetings</h3>
                                    <button class="teamhub-widget-action" id="open-meetings-btn">Open</button>
                                </div>
                                <div class="teamhub-widget-content" id="meetings-list">
                                    <div class="teamhub-loading-small">Loading meetings...</div>
                                </div>
                            </div>
                            <div class="teamhub-widget">
                                <h3>Members</h3>
                                <div class="teamhub-widget-content" id="team-members-list">
                                    <div class="teamhub-loading-small">Loading members...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Tab clicks (views rendered inside content area)
            document.querySelectorAll('.teamhub-menu-item[data-view]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    this.switchView(e.currentTarget.dataset.view);
                });
            });

            // Web link clicks (open in new tab)
            document.querySelectorAll('.teamhub-menu-item[data-link-url]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    window.open(e.currentTarget.dataset.linkUrl, '_blank');
                });
            });

            document.getElementById('manage-links-btn')?.addEventListener('click', () => this.renderManageLinks());

            // Load sidebar widgets
            this.loadResourceWidgets();
            this.loadTeamMembers();

            await this.switchView('msgstream');
        },
        
        async loadResourceWidgets() {
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/resources`)
                );
                
                if (!response.ok) {
                    console.log('TeamHub: Failed to fetch resources for widgets');
                    return;
                }
                
                const resources = await response.json();
                console.log('TeamHub: Resources for widgets:', resources);
                
                // Calendar widget
                if (resources.calendar) {
                    const widget = document.getElementById('calendar-widget');
                    if (widget) {
                        widget.style.display = 'block';
                        document.getElementById('open-calendar-btn')?.addEventListener('click', () => {
                            window.open(OC.generateUrl('/apps/calendar'), '_blank');
                        });
                        this.loadCalendarEvents();
                    }
                }
                
                // Deck widget
                if (resources.deck && resources.deck.board_id) {
                    const widget = document.getElementById('deck-widget');
                    if (widget) {
                        widget.style.display = 'block';
                        const deckUrl = OC.generateUrl('/apps/deck/board/' + resources.deck.board_id);
                        document.getElementById('open-deck-btn')?.addEventListener('click', () => {
                            window.open(deckUrl, '_blank');
                        });
                        this.loadDeckTasks(resources.deck.board_id);
                    }
                }
                
                // Meetings widget
                if (resources.talk && resources.talk.token) {
                    const widget = document.getElementById('meetings-widget');
                    if (widget) {
                        widget.style.display = 'block';
                        const talkUrl = OC.generateUrl('/call/' + resources.talk.token);
                        document.getElementById('open-meetings-btn')?.addEventListener('click', () => {
                            window.open(talkUrl, '_blank');
                        });
                        this.loadUpcomingMeetings(resources.talk.token);
                    }
                }
                
            } catch (error) {
                console.error('TeamHub: Error loading resource widgets:', error);
            }
        },
        
        async loadCalendarEvents() {
            const container = document.getElementById('calendar-events');
            if (!container) return;
            
            // Placeholder - would need Calendar API integration
            container.innerHTML = '<p class="teamhub-empty-small">Calendar integration coming soon</p>';
        },
        
        async loadDeckTasks(boardId) {
            const container = document.getElementById('deck-tasks');
            if (!container) return;
            
            console.log('TeamHub: Loading Deck tasks for board:', boardId);
            
            try {
                // Fetch Deck cards via OCS API
                const response = await fetch(
                    OC.generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks`),
                    {
                        headers: {
                            'OCS-APIRequest': 'true',
                            'Accept': 'application/json'
                        }
                    }
                );
                
                if (!response.ok) {
                    console.log('TeamHub: Failed to fetch Deck stacks');
                    container.innerHTML = '<p class="teamhub-empty-small">Could not load tasks</p>';
                    return;
                }
                
                const stacks = await response.json();
                console.log('TeamHub: Deck stacks:', stacks);
                
                // Collect all cards from all stacks
                let allCards = [];
                stacks.forEach(stack => {
                    if (stack.cards && Array.isArray(stack.cards)) {
                        stack.cards.forEach(card => {
                            if (!card.archived && !card.done && card.duedate) {
                                allCards.push({
                                    id: card.id,
                                    title: card.title,
                                    duedate: card.duedate,
                                    assignedUsers: card.assignedUsers || [],
                                    stackId: stack.id,
                                    boardId: boardId
                                });
                            }
                        });
                    }
                });
                
                console.log('TeamHub: Found', allCards.length, 'cards with due dates');
                
                if (allCards.length === 0) {
                    container.innerHTML = '<p class="teamhub-empty-small">No upcoming tasks</p>';
                    return;
                }
                
                // Sort by due date (closest first)
                allCards.sort((a, b) => {
                    const dateA = new Date(a.duedate);
                    const dateB = new Date(b.duedate);
                    return dateA - dateB;
                });
                
                // Take only first 5
                const upcomingCards = allCards.slice(0, 5);
                
                // Render cards
                const now = new Date();
                let html = '<ul class="teamhub-widget-list">';
                
                upcomingCards.forEach(card => {
                    const dueDate = new Date(card.duedate);
                    const isOverdue = dueDate < now;
                    const overdueClass = isOverdue ? 'overdue' : '';
                    
                    const dateStr = dueDate.toLocaleString(undefined, { 
                        month: 'short', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        year: dueDate.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
                    });
                    
                    const cardUrl = OC.generateUrl(`/apps/deck/board/${boardId}/card/${card.id}`);
                    
                    // Build avatar row for assigned users
                    let avatarHtml = '';
                    if (card.assignedUsers && card.assignedUsers.length > 0) {
                        avatarHtml = '<div class="teamhub-avatar-row">';
                        card.assignedUsers.forEach(u => {
                            const uid = u.participant?.uid || u.participant?.userId || '';
                            const name = u.participant?.displayname || uid || '?';
                            if (uid) {
                                avatarHtml += this.renderAvatar(uid, name, 24);
                            }
                        });
                        avatarHtml += '</div>';
                    }
                    
                    html += `
                        <li class="teamhub-widget-list-item">
                            <div class="teamhub-deck-card-row1">
                                <a href="${cardUrl}" target="_blank" class="teamhub-deck-card-title ${overdueClass}">
                                    ${this.escapeHtml(card.title)}
                                </a>
                                <span class="teamhub-deck-card-date ${overdueClass}">${dateStr}</span>
                            </div>
                            ${avatarHtml}
                        </li>
                    `;
                });
                
                html += '</ul>';
                container.innerHTML = html;
                this.initAvatarPopovers();
                
                console.log('TeamHub: Deck widget rendered with', upcomingCards.length, 'cards');
                
            } catch (error) {
                console.error('TeamHub: Error loading Deck tasks:', error);
                container.innerHTML = '<p class="teamhub-empty-small">Failed to load tasks</p>';
            }
        },
        
        renderAvatar(uid, name, size = 24) {
            if (!uid) return '';
            const initials = (name || uid).split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
            const avatarUrl = OC.generateUrl(`/avatar/${encodeURIComponent(uid)}/${size}`);
            // data-user and data-user-display-name trigger Nextcloud's built-in user popover
            return `<span class="teamhub-avatar-wrapper" 
                          data-user="${this.escapeHtml(uid)}" 
                          data-user-display-name="${this.escapeHtml(name || uid)}"
                          title="${this.escapeHtml(name || uid)}">
                        <img class="teamhub-avatar" 
                             src="${avatarUrl}" 
                             width="${size}" height="${size}"
                             alt="${this.escapeHtml(initials)}"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                        <span class="teamhub-avatar-placeholder" 
                              style="display:none;width:${size}px;height:${size}px;font-size:${Math.round(size * 0.4)}px;">
                            ${this.escapeHtml(initials)}
                        </span>
                    </span>`;
        },
        
        initAvatarPopovers() {
            // Nextcloud renders contact action menus for elements with data-user attribute
            // The contacts menu shows: location, local time, profile link, Talk, email
            document.querySelectorAll('.teamhub-avatar-wrapper[data-user]').forEach(el => {
                if (el._popoverInit) return;
                el._popoverInit = true;
                el.style.cursor = 'pointer';

                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const uid = el.dataset.user;
                    const displayName = el.dataset.userDisplayName || uid;

                    // Try Nextcloud 25+ ContactsMenu
                    if (typeof OCA !== 'undefined' && OCA.ContactsMenu) {
                        if (typeof OCA.ContactsMenu.open === 'function') {
                            OCA.ContactsMenu.open(uid, displayName, el);
                            return;
                        }
                        if (typeof OCA.ContactsMenu.toggleForEntry === 'function') {
                            OCA.ContactsMenu.toggleForEntry(el, uid, displayName);
                            return;
                        }
                    }

                    // Fallback: trigger Nextcloud's implicit popover via OCS
                    this.showUserPopover(uid, displayName, el);
                });
            });
        },

        async showUserPopover(uid, displayName, anchor) {
            // Remove any existing popover
            document.querySelector('.teamhub-user-popover')?.remove();

            const popover = document.createElement('div');
            popover.className = 'teamhub-user-popover';
            popover.innerHTML = `<div class="teamhub-user-popover-loading">Loading...</div>`;

            // Position below avatar
            const rect = anchor.getBoundingClientRect();
            popover.style.position = 'fixed';
            popover.style.top = (rect.bottom + 6) + 'px';
            popover.style.left = rect.left + 'px';
            document.body.appendChild(popover);

            try {
                // Fetch user info from Nextcloud OCS
                const r = await fetch(
                    OC.generateUrl(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`),
                    { headers: { 'OCS-APIRequest': 'true' } }
                );
                const json = await r.json();
                const u = json?.ocs?.data || {};

                const email = u.email || '';
                const displayNameFull = u.displayname || displayName;
                const location = u['profile-enabled'] !== false ? OC.generateUrl(`/u/${uid}`) : null;
                const talkUrl = typeof OCA !== 'undefined' && OCA.Talk
                    ? OC.generateUrl(`/call/${uid}`)
                    : OC.generateUrl(`/apps/spreed?callUser=${uid}`);

                const avatarUrl = OC.generateUrl(`/avatar/${encodeURIComponent(uid)}/64`);

                popover.innerHTML = `
                    <div class="teamhub-popover-header">
                        <img src="${avatarUrl}" class="teamhub-popover-avatar" alt="">
                        <div class="teamhub-popover-name">${this.escapeHtml(displayNameFull)}</div>
                    </div>
                    <div class="teamhub-popover-actions">
                        ${location ? `<a href="${location}" target="_blank" class="teamhub-popover-action">👤 View profile</a>` : ''}
                        <a href="${talkUrl}" target="_blank" class="teamhub-popover-action">💬 Talk to ${this.escapeHtml(displayNameFull)}</a>
                        ${email ? `<a href="mailto:${this.escapeHtml(email)}" class="teamhub-popover-action">✉️ ${this.escapeHtml(email)}</a>` : ''}
                    </div>
                `;
            } catch(e) {
                popover.innerHTML = `<div class="teamhub-popover-actions">
                    <span class="teamhub-popover-action">${this.escapeHtml(displayName)}</span>
                </div>`;
            }

            // Close when clicking outside
            setTimeout(() => {
                document.addEventListener('click', function closePopover(e) {
                    if (!popover.contains(e.target) && e.target !== anchor) {
                        popover.remove();
                        document.removeEventListener('click', closePopover);
                    }
                });
            }, 50);
        },

        async loadUpcomingMeetings(token) {
            const container = document.getElementById('meetings-list');
            if (!container) return;
            
            // Placeholder - would need Talk API integration
            container.innerHTML = '<p class="teamhub-empty-small">Meetings integration coming soon</p>';
        },
        
        async getTeamAppLinks() {
            if (!this.currentTeamId) {
                console.log('TeamHub: No current team ID');
                return '';
            }
            
            console.log('TeamHub: Fetching resources for team:', this.currentTeamId);
            
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/resources`)
                );
                
                console.log('TeamHub: Resources response status:', response.status);
                
                if (!response.ok) {
                    console.log('TeamHub: Failed to fetch resources');
                    return '';
                }
                
                const resources = await response.json();
                console.log('TeamHub: Resources data:', resources);
                
                let links = '';
                
                // Talk/Chat
                if (resources.talk && resources.talk.token) {
                    const talkUrl = OC.generateUrl('/call/' + resources.talk.token);
                    links += `<button class="teamhub-menu-item" data-link-url="${talkUrl}">💬 Chat</button>`;
                    console.log('TeamHub: Added Talk link:', talkUrl);
                }
                
                // Files
                if (resources.files && resources.files.path) {
                    const filesUrl = OC.generateUrl('/apps/files/?dir=' + encodeURIComponent(resources.files.path));
                    links += `<button class="teamhub-menu-item" data-link-url="${filesUrl}">📁 Files</button>`;
                    console.log('TeamHub: Added Files link:', filesUrl);
                }
                
                // Calendar
                if (resources.calendar) {
                    const calUrl = OC.generateUrl('/apps/calendar');
                    links += `<button class="teamhub-menu-item" data-link-url="${calUrl}">📅 Calendar</button>`;
                    console.log('TeamHub: Added Calendar link');
                }
                
                // Deck
                if (resources.deck && resources.deck.board_id) {
                    const deckUrl = OC.generateUrl('/apps/deck/board/' + resources.deck.board_id);
                    links += `<button class="teamhub-menu-item" data-link-url="${deckUrl}">📋 Deck</button>`;
                    console.log('TeamHub: Added Deck link:', deckUrl);
                }
                
                console.log('TeamHub: Total app links generated:', links.length > 0 ? 'yes' : 'none');
                return links;
                
            } catch (error) {
                console.error('TeamHub: Error fetching resources:', error);
                return '';
            }
        },
        
        async loadTeamMembers() {
            const container = document.getElementById('team-members-list');
            if (!container) {
                console.log('TeamHub: Members container not found');
                return;
            }
            
            console.log('TeamHub: Loading members for team:', this.currentTeamId);
            
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/members`)
                );
                
                console.log('TeamHub: Members response status:', response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                console.log('TeamHub: Members data received:', data);
                
                // Handle both direct array and error object responses
                let members = [];
                if (Array.isArray(data)) {
                    members = data;
                } else if (data.error && Array.isArray(data.members)) {
                    members = data.members;
                    if (data.error) {
                        console.warn('TeamHub: Members API returned error:', data.error);
                    }
                }
                
                console.log('TeamHub: Processed members:', members);
                
                if (members.length === 0) {
                    container.innerHTML = '<p class="teamhub-empty-small">No members found</p>';
                    return;
                }
                
                let html = '<ul class="teamhub-member-list">';
                members.forEach(member => {
                    const uid = member.userId || '';
                    const name = member.displayName || uid;
                    const roleClass = (member.role || 'member').toLowerCase();
                    
                    html += `<li class="teamhub-member-item">
                        ${this.renderAvatar(uid, name, 32)}
                        <div class="teamhub-member-info">
                            <span class="teamhub-member-name">${this.escapeHtml(name)}</span>
                            <span class="teamhub-member-role teamhub-role-${roleClass}">${this.escapeHtml(member.role)}</span>
                        </div>
                    </li>`;
                });
                html += '</ul>';
                
                container.innerHTML = html;
                this.initAvatarPopovers();
                console.log('TeamHub: Members rendered successfully');
                
            } catch (error) {
                console.error('TeamHub: Error loading members:', error);
                container.innerHTML = '<p class="teamhub-empty-small">Failed to load members</p>';
            }
        },
        
        async switchView(view) {
            this.currentView = view;

            // Update active tab
            document.querySelectorAll('.teamhub-menu-item[data-view]').forEach(item => {
                item.classList.toggle('active', item.dataset.view === view);
            });

            const res = this.currentResources || {};

            if (view === 'msgstream') {
                // Restore two-column layout for messages
                this.showContentArea();
                await this.renderMessageStream();
                return;
            }

            // For app tabs: replace the entire content-area with a full-height iframe
            let url = null;
            let label = '';

            if (view === 'talk' && res.talk?.token) {
                url   = OC.generateUrl('/apps/spreed/?token=' + res.talk.token);
                label = '💬 Chat';
            } else if (view === 'files' && res.files?.path) {
                url   = OC.generateUrl('/apps/files/?dir=' + encodeURIComponent(res.files.path));
                label = '📁 Files';
            } else if (view === 'calendar') {
                url   = OC.generateUrl('/apps/calendar');
                label = '📅 Calendar';
            } else if (view === 'deck' && res.deck?.board_id) {
                url   = OC.generateUrl('/apps/deck/board/' + res.deck.board_id);
                label = '📋 Deck';
            }

            if (!url) {
                this.showContentArea();
                document.getElementById('team-content').innerHTML =
                    `<div class="teamhub-empty">This resource is not configured for this team.</div>`;
                return;
            }

            this.showEmbedArea(url, label);
        },

        showContentArea() {
            // Restore normal two-column layout
            const area = document.querySelector('.teamhub-content-area');
            if (!area) return;
            area.style.display = '';
            area.style.gridTemplateColumns = '';
            // Make sure team-content and sidebar are visible
            const content = document.getElementById('team-content');
            const sidebar = area.querySelector('.teamhub-sidebar');
            if (content) content.style.display = '';
            if (sidebar) sidebar.style.display = '';
        },

        showEmbedArea(url, label) {
            const area = document.querySelector('.teamhub-content-area');
            if (!area) return;

            // Switch to single-column: iframe takes everything, no sidebar
            area.style.display = 'block';
            area.style.padding = '0';

            const content = document.getElementById('team-content');
            const sidebar = area.querySelector('.teamhub-sidebar');

            if (sidebar) sidebar.style.display = 'none';

            if (content) {
                content.style.display = 'block';
                content.style.height = '100%';
                content.style.overflow = 'hidden';
                content.innerHTML = `
                    <div class="teamhub-embed-bar">
                        <span class="teamhub-embed-label">${label}</span>
                        <a href="${url}" target="_blank" class="teamhub-widget-action">↗ Open in new tab</a>
                    </div>
                    <iframe id="teamhub-app-frame"
                            src="${url}"
                            class="teamhub-app-iframe"
                            sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-top-navigation-by-user-activation"
                            allowfullscreen>
                    </iframe>
                `;

                // Once iframe loads, hide Nextcloud's navigation chrome via same-origin CSS
                const frame = document.getElementById('teamhub-app-frame');
                frame.addEventListener('load', () => {
                    try {
                        const doc = frame.contentDocument || frame.contentWindow.document;
                        const style = doc.createElement('style');
                        style.textContent = `
                            /* Hide Nextcloud navigation chrome */
                            #header,
                            #navigation,
                            #app-navigation,
                            #app-navigation-toggle,
                            .app-navigation,
                            nav.app-navigation,
                            #header-menu-profilemenu,
                            .header-end,
                            .header-start,
                            .header-menu,
                            [class*="header"],
                            #nc-user-menu,
                            .unified-search,
                            .notifications-button,
                            #appmenu,
                            .app-menu { display: none !important; }

                            /* Remove top padding that was reserved for header */
                            body, #content, #content-vue, #app-content, #app-content-wrapper {
                                padding-top: 0 !important;
                                margin-top: 0 !important;
                                top: 0 !important;
                            }

                            /* Make app content fill full height */
                            #app-content, #app-content-vue, .app-content {
                                height: 100vh !important;
                                max-height: 100vh !important;
                            }
                        `;
                        doc.head.appendChild(style);
                    } catch(e) {
                        // Cross-origin or blocked — iframe shows normally
                        console.log('TeamHub: Could not inject iframe styles:', e.message);
                    }
                });
            }
        },
        
        async renderMessageStream() {
            const content = document.getElementById('team-content');
            content.innerHTML = `
                <div class="teamhub-messages-container">
                    <h2 class="teamhub-section-header">Team Messages</h2>
                    <div id="messages-list" class="teamhub-loading">Loading messages...</div>
                </div>
            `;
            await this.loadTeamMessages();
        },

        async loadTeamMessages() {
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/messages`)
                );

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const data = await response.json();
                const container = document.getElementById('messages-list');
                container.classList.remove('teamhub-loading');
                let messages = Array.isArray(data) ? data : (data.teams || []);

                if (messages.length === 0) {
                    container.innerHTML = `
                        <div class="teamhub-empty-state">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                            <h3>No messages yet</h3>
                            <p>Be the first to post a message in this team!</p>
                            <button class="primary" id="first-message-btn">Post First Message</button>
                        </div>
                    `;
                    document.getElementById('first-message-btn')?.addEventListener('click', () => this.renderPostMessage());
                    return;
                }

                let html = '<div class="teamhub-message-list">';
                messages.forEach(msg => {
                    const date = new Date(msg.created_at * 1000).toLocaleString();
                    const renderedMessage = this.renderMarkdown(msg.message);
                    const isPriority = msg.priority === 'priority';
                    const priorityClass = isPriority ? 'teamhub-message--priority' : '';
                    const priorityBadge = isPriority
                        ? '<span class="teamhub-priority-badge">🔴 Priority</span>'
                        : '';
                    const commentCount = msg.comment_count || 0;
                    const commentLabel = commentCount === 1 ? '1 comment' : `${commentCount} comments`;

                    html += `
                        <div class="teamhub-message ${priorityClass}" data-message-id="${msg.id}">
                            <div class="teamhub-message-header">
                                <div class="teamhub-message-meta">
                                    <span class="teamhub-message-author">${this.escapeHtml(msg.author_id)}</span>
                                    <span class="teamhub-message-date">${date}</span>
                                    ${priorityBadge}
                                </div>
                            </div>
                            <div class="teamhub-message-subject">${this.escapeHtml(msg.subject)}</div>
                            <div class="teamhub-message-body">${renderedMessage}</div>
                            <div class="teamhub-message-footer">
                                <button class="teamhub-comment-toggle" data-message-id="${msg.id}">
                                    💬 ${commentLabel}
                                </button>
                            </div>
                            <div class="teamhub-comments-section" id="comments-${msg.id}" style="display:none;"></div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;

                // Attach comment toggle handlers
                document.querySelectorAll('.teamhub-comment-toggle').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const msgId = e.target.dataset.messageId;
                        this.toggleComments(msgId);
                    });
                });

            } catch (error) {
                console.error('Error loading team messages:', error);
                document.getElementById('messages-list').innerHTML =
                    `<div class="teamhub-empty">Failed to load messages: ${error.message}</div>`;
            }
        },

        async toggleComments(messageId) {
            const section = document.getElementById(`comments-${messageId}`);
            if (!section) return;

            if (section.style.display !== 'none') {
                section.style.display = 'none';
                return;
            }

            section.style.display = 'block';
            section.innerHTML = '<div class="teamhub-loading-small">Loading comments...</div>';

            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/messages/${messageId}/comments`)
                );
                const comments = await response.json();

                let html = '<div class="teamhub-comments">';

                if (Array.isArray(comments) && comments.length > 0) {
                    comments.forEach(c => {
                        const date = new Date(c.created_at * 1000).toLocaleString();
                        html += `
                            <div class="teamhub-comment">
                                <div class="teamhub-comment-header">
                                    <span class="teamhub-comment-author">${this.escapeHtml(c.author_id)}</span>
                                    <span class="teamhub-comment-date">${date}</span>
                                </div>
                                <div class="teamhub-comment-body">${this.renderMarkdown(c.comment)}</div>
                            </div>
                        `;
                    });
                } else {
                    html += '<p class="teamhub-empty-small">No comments yet.</p>';
                }

                html += `
                    <div class="teamhub-add-comment">
                        <textarea id="comment-input-${messageId}" placeholder="Write a comment... (Markdown supported)" rows="2"></textarea>
                        <button class="primary teamhub-submit-comment" data-message-id="${messageId}">Post Comment</button>
                    </div>
                </div>`;

                section.innerHTML = html;

                section.querySelector('.teamhub-submit-comment')?.addEventListener('click', async (e) => {
                    const msgId = e.target.dataset.messageId;
                    const input = document.getElementById(`comment-input-${msgId}`);
                    if (!input || !input.value.trim()) return;
                    await this.postComment(msgId, input.value.trim());
                    // Reload comments
                    this.toggleComments(msgId);
                    this.toggleComments(msgId);
                    // Update count
                    await this.loadTeamMessages();
                });

            } catch (error) {
                section.innerHTML = '<div class="teamhub-empty-small">Failed to load comments</div>';
            }
        },

        async postComment(messageId, comment) {
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/messages/${messageId}/comments`),
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': OC.requestToken
                        },
                        body: JSON.stringify({ comment })
                    }
                );
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                this.showSuccess('Comment posted!');
            } catch (error) {
                this.showError('Failed to post comment: ' + error.message);
            }
        },
        
        async postMessage() {
            const teamSelect = document.getElementById('message-team');
            const subjectEl = document.getElementById('message-subject');
            const messageEl = document.getElementById('message-body');
            const priorityEl = document.getElementById('message-priority');
            
            if (!teamSelect || !subjectEl || !messageEl) return;
            
            const teamId = teamSelect.value;
            const subject = subjectEl.value.trim();
            const message = messageEl.value.trim();
            const priority = priorityEl ? priorityEl.value : 'normal';
            
            if (!subject || !message) {
                this.showError('Both subject and message are required');
                return;
            }
            
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages`),
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': OC.requestToken
                        },
                        body: JSON.stringify({ subject, message, priority })
                    }
                );
                
                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.error || `HTTP ${response.status}`);
                }
                
                this.showSuccess('Message posted successfully!');
                // Navigate to the team where message was posted
                await this.selectTeam(teamId);
            } catch (error) {
                console.error('Error posting message:', error);
                this.showError('Failed to post message: ' + error.message);
            }
        },
        
        renderCreateTeam() {
            const main = document.getElementById('teamhub-main');
            
            main.innerHTML = `
                <div class="teamhub-form-view">
                    <h2>Create New Team</h2>
                    <form id="create-team-form" class="teamhub-form">
                        <div class="form-group">
                            <label for="team-name">Team Name *</label>
                            <input type="text" id="team-name" placeholder="Enter team name" required>
                        </div>
                        <div class="form-group">
                            <label for="team-description">Description</label>
                            <textarea id="team-description" placeholder="Describe your team..." rows="4"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="primary">Create Team</button>
                            <button type="button" class="secondary" id="cancel-create">Cancel</button>
                        </div>
                    </form>
                </div>
            `;
            
            document.getElementById('create-team-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.createTeam();
            });
            
            document.getElementById('cancel-create').addEventListener('click', () => {
                this.renderWelcome();
            });
        },
        
        async createTeam() {
            const nameInput = document.getElementById('team-name');
            const descInput = document.getElementById('team-description');
            
            if (!nameInput) return;
            
            const name = nameInput.value.trim();
            const description = descInput ? descInput.value.trim() : '';
            
            if (!name) {
                this.showError('Team name is required');
                return;
            }
            
            try {
                const response = await fetch(OC.generateUrl('/apps/teamhub/api/v1/teams'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({ name, description })
                });
                
                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.error || `HTTP ${response.status}`);
                }
                
                const team = await response.json();
                this.showSuccess(`Team "${name}" created successfully!`);
                
                // Reload teams and select the new one
                await this.loadTeams();
                this.selectTeam(team.id);
            } catch (error) {
                console.error('Error creating team:', error);
                this.showError('Failed to create team: ' + error.message);
            }
        },
        
        renderPostMessage() {
            const main = document.getElementById('teamhub-main');
            
            let teamOptions = '';
            this.teams.forEach(team => {
                const selected = team.id === this.currentTeamId ? 'selected' : '';
                teamOptions += `<option value="${team.id}" ${selected}>${this.escapeHtml(team.name)}</option>`;
            });
            
            main.innerHTML = `
                <div class="teamhub-form-view">
                    <h2>Post Message</h2>
                    <form id="post-message-form" class="teamhub-form">
                        <div class="form-group">
                            <label for="message-team">Team *</label>
                            <select id="message-team" required>${teamOptions}</select>
                        </div>
                        <div class="form-group">
                            <label for="message-priority">Priority</label>
                            <div class="teamhub-priority-selector">
                                <label class="teamhub-priority-option">
                                    <input type="radio" name="priority" id="message-priority" value="normal" checked>
                                    <span class="priority-normal">📢 Normal Message</span>
                                    <small>Sends a Nextcloud notification to all team members</small>
                                </label>
                                <label class="teamhub-priority-option">
                                    <input type="radio" name="priority" value="priority">
                                    <span class="priority-urgent">🔴 Priority Message</span>
                                    <small>Sends a Nextcloud notification <strong>and an email</strong> to all team members</small>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="message-subject">Subject *</label>
                            <input type="text" id="message-subject" placeholder="Message subject" required>
                        </div>
                        <div class="form-group">
                            <label for="message-body">Message *</label>
                            <textarea id="message-body" placeholder="Write your message (Markdown supported)" rows="10" required></textarea>
                            <small class="form-help">Supports **bold**, *italic*, [link](url), \`code\`, and more.</small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="primary">Post Message</button>
                            <button type="button" class="secondary" id="cancel-post">Cancel</button>
                        </div>
                    </form>
                </div>
            `;
            
            document.getElementById('post-message-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const priority = document.querySelector('input[name="priority"]:checked')?.value || 'normal';
                document.getElementById('message-priority').value = priority;
                await this.postMessage();
            });
            
            document.getElementById('cancel-post').addEventListener('click', () => {
                if (this.currentTeamId) this.selectTeam(this.currentTeamId);
                else this.renderWelcome();
            });
        },
        
        async renderManageLinks() {
            if (!this.currentTeamId) return;
            
            const main = document.getElementById('teamhub-main');
            
            // Fetch existing links
            let existingLinks = [];
            try {
                const r = await fetch(OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/links`));
                if (r.ok) {
                    const data = await r.json();
                    if (Array.isArray(data)) existingLinks = data;
                }
            } catch (e) {}
            
            let linksHtml = '';
            if (existingLinks.length > 0) {
                linksHtml = '<ul class="teamhub-link-list">';
                existingLinks.forEach(link => {
                    linksHtml += `
                        <li class="teamhub-link-item">
                            <div class="teamhub-link-info">
                                <span class="teamhub-link-title">${this.escapeHtml(link.title)}</span>
                                <a href="${this.escapeHtml(link.url)}" target="_blank" class="teamhub-link-url">${this.escapeHtml(link.url)}</a>
                            </div>
                            <button class="teamhub-link-delete secondary" data-link-id="${link.id}">Delete</button>
                        </li>`;
                });
                linksHtml += '</ul>';
            } else {
                linksHtml = '<p class="teamhub-empty-small">No links added yet.</p>';
            }
            
            main.innerHTML = `
                <div class="teamhub-form-view">
                    <h2>Team Links</h2>
                    
                    <div class="teamhub-widget" style="margin-bottom:24px;">
                        <h3>Existing Links</h3>
                        <div id="existing-links">${linksHtml}</div>
                    </div>
                    
                    <h3>Add New Link</h3>
                    <form id="add-link-form" class="teamhub-form">
                        <div class="form-group">
                            <label for="link-title">Title *</label>
                            <input type="text" id="link-title" placeholder="e.g. Project Wiki" required>
                        </div>
                        <div class="form-group">
                            <label for="link-url">URL *</label>
                            <input type="url" id="link-url" placeholder="https://..." required>
                            <small class="form-help" id="url-error" style="color:#d32f2f;display:none;">
                                URL must start with http:// or https://
                            </small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="primary">Save Link</button>
                            <button type="button" class="secondary" id="cancel-links">Cancel</button>
                        </div>
                    </form>
                </div>
            `;
            
            // Delete existing link handlers
            document.querySelectorAll('.teamhub-link-delete').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const linkId = e.target.dataset.linkId;
                    if (!confirm('Delete this link?')) return;
                    await this.deleteLink(linkId);
                    this.renderManageLinks();
                });
            });
            
            // URL validation + submit
            const urlInput = document.getElementById('link-url');
            const urlError = document.getElementById('url-error');
            
            urlInput.addEventListener('input', () => {
                const val = urlInput.value.trim();
                const valid = val === '' || val.startsWith('http://') || val.startsWith('https://');
                urlError.style.display = valid ? 'none' : 'block';
            });
            
            document.getElementById('add-link-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const title = document.getElementById('link-title').value.trim();
                const url = urlInput.value.trim();
                
                if (!url.startsWith('http://') && !url.startsWith('https://')) {
                    urlError.style.display = 'block';
                    urlInput.focus();
                    return;
                }
                
                await this.saveLink(title, url);
            });
            
            document.getElementById('cancel-links').addEventListener('click', () => {
                this.selectTeam(this.currentTeamId);
            });
        },
        
        async saveLink(title, url) {
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/links`),
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': OC.requestToken
                        },
                        body: JSON.stringify({ title, url })
                    }
                );
                
                if (!response.ok) {
                    const err = await response.json();
                    throw new Error(err.error || `HTTP ${response.status}`);
                }
                
                this.showSuccess(`Link "${title}" saved!`);
                // Reload team view to show new link in menu
                await this.selectTeam(this.currentTeamId);
            } catch (error) {
                this.showError('Failed to save link: ' + error.message);
            }
        },
        
        async deleteLink(linkId) {
            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/links/${linkId}`),
                    {
                        method: 'DELETE',
                        headers: { 'requesttoken': OC.requestToken }
                    }
                );
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                this.showSuccess('Link deleted.');
            } catch (error) {
                this.showError('Failed to delete link: ' + error.message);
            }
        },
        
        filterTeams(query) {
            const items = document.querySelectorAll('.teamhub-team-item');
            const lowerQuery = query.toLowerCase();
            
            items.forEach(item => {
                const name = item.querySelector('.teamhub-team-name').textContent.toLowerCase();
                item.style.display = name.includes(lowerQuery) ? '' : 'none';
            });
        },
        
        renderMarkdown(text) {
            if (!text) return '';
            
            let html = text;
            
            // Code blocks (```code```)
            html = html.replace(/```([^`]+)```/g, '<pre><code>$1</code></pre>');
            
            // Inline code (`code`)
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            
            // Bold (**text** or __text__)
            html = html.replace(/\*\*([^\*]+)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
            
            // Italic (*text* or _text_)
            html = html.replace(/\*([^\*]+)\*/g, '<em>$1</em>');
            html = html.replace(/_([^_]+)_/g, '<em>$1</em>');
            
            // Links [text](url)
            html = html.replace(/\[([^\]]+)\]\(([^\)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
            
            // Line breaks
            html = html.replace(/\n/g, '<br>');
            
            return html;
        },
        
        renderReactions(messageId, reactions) {
            // reactions format: { '👍': ['user1', 'user2'], '❤️': ['user3'] }
            let html = '';
            for (const [emoji, users] of Object.entries(reactions)) {
                const count = users.length;
                const hasReacted = users.includes(OC.getCurrentUser().uid);
                const activeClass = hasReacted ? 'active' : '';
                html += `<button class="teamhub-reaction ${activeClass}" 
                               data-message-id="${messageId}" 
                               data-emoji="${emoji}">
                            ${emoji} ${count}
                        </button>`;
            }
            return html;
        },
        
        showReactionPicker(messageId) {
            const reactions = [
                { emoji: '👍', text: '+1' },
                { emoji: '❤️', text: 'heart' },
                { emoji: '😊', text: 'smile' },
                { emoji: '🎉', text: 'tada' },
                { emoji: '🚀', text: 'rocket' },
                { emoji: '👀', text: 'eyes' }
            ];
            
            const picker = document.createElement('div');
            picker.className = 'teamhub-reaction-picker';
            
            reactions.forEach(reaction => {
                const btn = document.createElement('button');
                btn.innerHTML = `<span class="emoji">${reaction.emoji}</span><span class="text">:${reaction.text}:</span>`;
                btn.addEventListener('click', () => {
                    this.addReaction(messageId, reaction.emoji);
                    picker.remove();
                });
                picker.appendChild(btn);
            });
            
            const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
            messageEl.appendChild(picker);
            
            // Close on click outside
            setTimeout(() => {
                document.addEventListener('click', function closePickerHandler(e) {
                    if (!picker.contains(e.target)) {
                        picker.remove();
                        document.removeEventListener('click', closePickerHandler);
                    }
                });
            }, 100);
        },
        
        async addReaction(messageId, emoji) {
            // For now, just show notification - backend needs reaction support
            this.showSuccess(`Reaction ${emoji} added!`);
            console.log('Add reaction:', messageId, emoji);
            // TODO: Implement backend API for reactions
        },
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        showError(message) {
            OC.Notification.showTemporary(message);
        },
        
        showSuccess(message) {
            OC.Notification.showTemporary(message);
        }
    };
    
    // Make it globally accessible
    window.TeamHubApp = TeamHubApp;
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => TeamHubApp.init());
    } else {
        TeamHubApp.init();
    }
})();
