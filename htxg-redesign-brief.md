# HTXG.PRO — Full Website Redesign Brief

## 1. Project Scope

Redesign the entire HTXG.PRO website with a modern, professional, dark, data-focused **traffic analytics dashboard** direction.

The redesign is UI/UX only:

- Do not add new features.
- Do not change existing business logic.
- Do not change how points, task completion, verification, or withdrawals work.
- Preserve all current functionality.
- Improve information hierarchy, visual consistency, usability, trust, and overall product quality.

## 2. Design References

Use the following references for visual direction only. Do not copy them exactly.

1. **Web Traffic Analysis Dashboard Design**  
   https://dribbble.com/shots/25977821-Web-Traffic-analysis-dashboard-design  
   Use for: dashboard layout, charts, data tables, information hierarchy, analytics-style UI.

2. **Website Traffic Analytics Dashboard Widgets**  
   https://dribbble.com/shots/27032024-Website-Traffic-Analytics-Dashboard-Widgets  
   Use for: modular widgets, KPI cards, mini charts, dark UI, electric-blue accent color.

## 3. Design Direction

### 3.1 Overall Style

- Dark-first, modern, professional, and data-dense.
- Prioritize tables, widgets, charts, statuses, and clear hierarchy.
- Minimal decoration; avoid unnecessary visual effects.
- The product should feel like a professional SaaS/analytics platform, not a generic “earn money online” website.
- Avoid excessive gamification.

### 3.2 Color System

| Role | Color | Usage |
|---|---|---|
| Primary background | `#020202` | Body, sidebar, dashboard background |
| Card background | `#0B0D10` / `#101318` | Widgets, tables, panels |
| Border | `#1E242B` | Dividers, card borders |
| Primary accent | `#0F2BF0` | Primary buttons, charts, links, active states |
| Primary text | `#FBFBFB` | Headings, key metrics |
| Secondary text | `#A19A9C` | Labels, metadata, timestamps |
| Success | `#32834C` | Completed, paid, success states |
| Error | `#E12914` | Rejected, failed states |
| Warning | `#F5B301` | Pending, cooldown, warning states |

Rules:

- Use only one main accent color: `#0F2BF0`.
- Do not use purple/blue/pink gradients.
- Avoid heavy glassmorphism.
- Avoid 3D icons.
- Avoid emojis as primary icons.
- Use semantic status colors only for statuses, charts, and alerts.

### 3.3 Typography

- Primary font: **Inter** or **Geist**.
- Monospace font: **JetBrains Mono** or **Geist Mono** for task IDs, transaction IDs, and timestamps.
- Headings: 18–24px, semibold or bold.
- Body text: 13–15px.
- Labels/metadata: 11–12px, uppercase with slight letter-spacing.
- KPI numbers: 20–28px, bold, tabular numbers.

### 3.4 Shape and Spacing

- Card radius: 12–16px.
- Button radius: 8–10px.
- Borders: 1px.
- Shadows: very subtle or none.
- Spacing grid: 8px.
- Dashboard container: 1280–1440px.
- Section spacing: 24–32px.

## 4. Website Structure

Keep the existing product structure. Reorganize and redesign the interface only.

### 4.1 User Area

- Landing page
- Login / Register
- Dashboard
- Tasks
- Task detail
- Wallet
- Withdraw
- Transaction history
- Profile / Settings
- Notifications

### 4.2 Admin / Advertiser Area

Redesign only the existing admin/advertiser functionality:

- Admin dashboard
- Task / campaign list
- Task / campaign detail
- User management
- Withdrawal / payout management
- Traffic reports
- Settings

## 5. Landing Page

The landing page must clearly communicate the existing value proposition:

- Official task links.
- Payment per completed task.
- Minimum withdrawal: 10,000 VND.
- Withdrawals via MoMo, ZaloPay, and bank transfer.

### Recommended Structure

1. **Hero**
   - Headline: “Official task links — paid per completed task.”
   - Subheadline: Explain that users complete tasks, earn points, and withdraw via MoMo, ZaloPay, or bank transfer.
   - Primary CTA: “Start earning”
   - Secondary CTA: “See how it works”

2. **How It Works**
   - Choose a task.
   - Open the link and complete the requirement.
   - System verifies the completion.
   - Receive points and withdraw rewards.

3. **Trust Section**
   - Payment per completed task.
   - Minimum withdrawal: 10,000 VND.
   - MoMo, ZaloPay, and bank withdrawal support.
   - Transparent points and transaction history.

4. **Task Preview**
   - Show a few example tasks currently available.
   - Display task name, reward, duration, and status.

5. **FAQ**
   - Keep existing FAQ content.
   - Present it using a clean accordion component.

6. **Final CTA**
   - “Create account” or “Start now”.

## 6. User Dashboard

The dashboard is the main screen after login.

### 6.1 Recommended Layout

```text
┌──────────────────────────────────────────────┐
│ Topbar: logo · search · notifications · wallet │
├──────────────────────────────────────────────┤
│ Available points │ Pending │ Tasks today │ 7-day earnings │
├──────────────────────────────┬───────────────┤
│ Earnings / completed chart   │ Daily status  │
├──────────────────────────────┼───────────────┤
│ Available tasks              │ Recent activity│
├──────────────────────────────┴───────────────┤
│ Traffic / task performance                    │
└──────────────────────────────────────────────┘
```

### 6.2 KPI Widgets

Display only metrics that already exist in the system, such as:

- Available points
- Pending points
- Tasks completed today
- Earnings in the last 7 days
- Total completed tasks
- Total withdrawn

Each KPI widget should include:

- Small label
- Large metric value
- Supporting note or mini chart, if data already exists
- No new metrics if the system does not currently track them

### 6.3 Charts

- Points earned over time
- Completed tasks over time
- Traffic by country or device, if this data already exists
- Use line or area charts with the primary accent color `#0F2BF0`
- Use subtle gridlines and avoid excessive decoration

### 6.4 Available Tasks

Show the latest 5–8 available or relevant tasks.

Each task row should include:

- Task ID
- Task name
- Reward
- Required duration
- Region or device, if applicable
- Status
- Action button: Start / Continue / View

## 7. Tasks

### 7.1 Task List

Convert the task list into a dense table or list layout.

Each task must display:

- Task ID
- Task name
- Task type
- Reward
- Required duration or completion requirement
- Region
- Device
- Status
- Deadline or remaining slots, if available
- Action button

Filters:

- All
- Available
- Running
- Verifying
- Completed
- Rejected
- Cooldown

Sorting:

- Highest reward
- Newest
- Time remaining
- Status

### 7.2 Task Statuses

| Status | Color | Meaning |
|---|---|---|
| Available | Gray / white | Can be started |
| Running | Electric blue `#0F2BF0` | Currently in progress |
| Verifying | Yellow `#F5B301` | Completion is being verified |
| Completed | Green `#32834C` | Task completed |
| Paid | Green | Points have been credited |
| Rejected | Red `#E12914` | Task was rejected |
| Cooldown | Gray | Task cannot be repeated yet |

### 7.3 Task Detail

Open as a side panel or dedicated page.

Display:

- Task name
- Task ID
- Reward
- Destination link, partially masked if needed
- Completion requirements
- Minimum page duration
- Allowed region
- Allowed device
- Current status
- Countdown timer, if running
- Start / Open link / Continue button
- Rejection reason, if applicable
- Status history, if already stored in the system

Example:

```text
Task #A291
Visit landing page

Reward: +30 points
Minimum duration: 20 seconds
Region: Vietnam
Device: Desktop / Mobile

Status: Running
Time remaining: 00:12

[Open link] [Cancel task]
```

## 8. Wallet and Withdrawals

### 8.1 Wallet

The wallet should feel like a professional financial wallet, not a game wallet.

Include:

- Available balance
- Pending balance
- Total earned
- Total withdrawn
- Minimum withdrawal: 10,000 VND
- Withdrawal methods: MoMo, ZaloPay, bank transfer
- Withdraw button
- Earnings chart, if data already exists
- Transaction history table

### 8.2 Transaction History

Each transaction row should include:

- Transaction ID
- Transaction type
- Points or amount
- Status
- Timestamp
- Note or rejection reason, if applicable

Statuses:

- Pending
- Processing
- Completed
- Rejected

### 8.3 Withdraw Page

Keep the existing withdrawal logic. Redesign only the form and flow:

- Available balance
- Withdrawal amount
- Warning if below 10,000 VND
- Withdrawal method: MoMo / ZaloPay / Bank
- Payout details input
- Confirmation step before submission
- Request status after submission

## 9. Admin / Advertiser Panel

Redesign only the existing admin functionality.

### 9.1 Admin Dashboard

KPIs:

- Total tasks
- Completed tasks
- Valid traffic
- Invalid traffic
- Total spend
- Total user payouts

Charts:

- Tasks or clicks over time
- Valid vs invalid traffic
- Traffic by country
- Traffic by device
- Performance by task or campaign

### 9.2 Campaign / Task List

Each row should include:

- ID
- Task name
- Destination URL
- Budget
- Cost per completion
- Completed count
- Valid traffic
- Status
- Created date
- Actions

### 9.3 Task / Campaign Detail

Display:

- Task information
- Progress
- Completed count
- Valid traffic rate
- Table of users who completed the task
- Payout status
- Rejection reasons, if applicable

## 10. Design System

The designer must deliver a complete design system including:

- Color tokens
- Typography scale
- Spacing scale
- Grid system
- Buttons: primary, secondary, ghost, disabled, destructive
- Inputs, selects, search fields, date ranges
- Tables
- Pagination
- Tabs
- Filter chips
- Status pills
- Badges
- Tooltips
- Toasts / notifications
- Modals
- Side panels
- Empty states
- Skeleton loading
- Chart components
- KPI cards
- Task rows / cards
- Transaction rows
- Sidebar and topbar navigation

## 11. Required UI States

Every main screen must include:

- Loading skeleton
- Empty state
- Error state
- Success state
- Cooldown state
- Verification pending state
- Rejected state with reason
- Mobile responsive layout
- Tablet responsive layout
- Desktop responsive layout

Empty states must be specific, for example:

- “No tasks are currently available in your region.”
- “You have no transactions yet.”
- “Your withdrawal request is being processed.”

## 12. Responsive Behavior

- Desktop: prioritize tables, widgets, and charts.
- Tablet: reduce columns and group KPI cards.
- Mobile: convert tables into task cards while keeping reward, timer, and Start action visible.
- Mobile navigation: bottom navigation or drawer.
- Do not simply shrink the desktop layout for mobile.

## 13. Deliverables

The designer must deliver:

1. A complete Figma file organized by pages and components.
2. A complete design system.
3. Desktop, tablet, and mobile designs for all main screens.
4. Dark mode as the default theme.
5. Prototypes for the main flows:
   - Login → Dashboard → Task → Verification → Points credited → Withdraw
   - Admin: View tasks → View traffic → Manage payouts
6. Empty, error, and loading states for all main screens.
7. Developer handoff notes: color tokens, spacing, typography, breakpoints, and interaction behavior.

## 14. What Not To Do

- Do not add new features.
- Do not change points, task approval, verification, or withdrawal logic.
- Do not use purple/blue/pink gradients.
- Do not use heavy glassmorphism.
- Do not use 3D icons.
- Do not use generic illustrations.
- Do not use emojis as primary icons.
- Do not copy the reference designs exactly.
- Do not make the dashboard look like a default admin template.
- Do not hide important information such as reward, duration, status, or rejection reason.

## 15. Acceptance Criteria

The redesigned website must:

- Let a new user understand how to earn points within 10 seconds.
- Clearly show reward, duration, and status for every task.
- Feel like a professional SaaS/analytics product.
- Present wallet and transaction history clearly and credibly.
- Let admins easily track tasks, traffic, and payouts.
- Remain consistent across desktop, tablet, and mobile.
- Not look like a generic AI-generated template or landing page.
