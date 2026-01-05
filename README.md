# THWTHV Manager

A WordPress plugin designed to manage services (Dienste), shifts, and participant lists for THW (Technisches Hilfswerk) local sections.

## Description

This plugin provides a complete system for scheduling services and allowing WordPress users to sign up for them. It handles role assignments (Group Leader, Driver, Helper) and waitlists.

## Features

*   **Service Management**: Create and delete services with date, time, and location.
*   **Frontend Signup**: Logged-in users can sign up for upcoming services via a simple button.
*   **Role Management**: Assign specific roles to participants within a service:
    *   **GF**: Gruppenführer (Group Leader)
    *   **KF**: Kraftfahrer (Driver)
    *   **H**: Helfer (Helper)
    *   **N**: Warteliste (Waitlist - default upon signup)
*   **Notifications**: Email notifications are sent to configured addresses when users sign up or are removed.
*   **Frontend Administration**: Designated THV Admins can manage participants (change roles, remove users, add users manually) directly from the frontend list.
*   **Print View**: Generate a printer-friendly view (PDF export compatible) of the service list.

## Installation

1.  Upload the plugin folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Upon activation, the necessary database tables (`thv_dienste`, `thv_teilnehmer`, `thv_settings`) are created automatically.

## Usage

### Configuration (Backend)
1.  Navigate to **THV Einstellungen** in the WordPress Admin Dashboard.
2.  **Notification Emails**: Add email addresses that should receive notifications about signups.
3.  **THV Administrators**: Select WordPress users who should have administrative rights on the frontend (e.g., assigning roles, removing participants).

### Frontend Integration
Place the following shortcode on any page or post where you want the service list to appear:

```shortcode
[thv_dienste]
```

### User Workflow
1.  Users must be logged in to sign up.
2.  Users see a list of upcoming services (filtered by date).
3.  Clicking "Eintragen" adds them to the **Warteliste** (Waitlist).
4.  A THV Administrator can then assign them a specific role (GF, KF, H) or remove them if necessary via the frontend interface.

## Technical Details

*   **Text Domain**: `thwthv`
*   **License**: LGPL v2 or later
*   **Author**: Matthias Verwold

*Note: The user interface strings are currently hardcoded in German.*