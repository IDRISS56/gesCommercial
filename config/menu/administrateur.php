 <!DOCTYPE html>
 <html lang="fr">

 <head>
     <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <title>Sutura Group| Système de gestion</title>

     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
     <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
     <!-- Google Fonts -->
     <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

     <style>
         :root {
             --temenos-primary: #0a4d8c;
             --temenos-primary-dark: #063a6b;
             --temenos-primary-light: #1a6bb5;
             --temenos-accent: #00a3e0;
             --temenos-success: #10b981;
             --temenos-warning: #f59e0b;
             --temenos-danger: #ef4444;
             --temenos-info: #3b82f6;

             --bg-app: #f0f4f8;
             --bg-header: linear-gradient(135deg, #0a4d8c 0%, #063a6b 100%);
             --bg-toolbar: #ffffff;
             --bg-sidebar: #ffffff;
             --bg-content: #f0f4f8;
             --bg-window: #ffffff;
             --bg-window-header: linear-gradient(135deg, #0a4d8c 0%, #063a6b 100%);
             --bg-grid-header: #f8fafc;
             --bg-grid-row: #ffffff;
             --bg-grid-row-alt: #f8fafc;
             --bg-grid-hover: #e0f2fe;
             --bg-grid-selected: #bae6fd;
             --bg-input: #ffffff;
             --bg-group-header: #f1f5f9;
             --bg-toolbar-section: #f8fafc;

             --text-primary: #0f172a;
             --text-secondary: #475569;
             --text-muted: #94a3b8;
             --text-inverse: #ffffff;
             --text-accent: #0a4d8c;

             --border-color: #e2e8f0;
             --border-light: #f1f5f9;
             --border-dark: #cbd5e1;

             --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.04);
             --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
             --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
             --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);

             --radius-sm: 4px;
             --radius-md: 8px;
             --radius-lg: 12px;

             --transition-fast: 0.15s ease;
             --transition-normal: 0.25s ease;
             --transition-slow: 0.4s ease;

             --sidebar-width: 280px;
         }

         [data-theme="dark"] {
             --bg-app: #0f1724;
             --bg-header: linear-gradient(135deg, #1a2332 0%, #0f1724 100%);
             --bg-toolbar: #1a2332;
             --bg-sidebar: #1a2332;
             --bg-content: #0f1724;
             --bg-window: #1a2332;
             --bg-grid-header: #1e293b;
             --bg-grid-row: #1a2332;
             --bg-grid-row-alt: #162032;
             --bg-grid-hover: #1e3a5f;
             --bg-grid-selected: #1e4976;
             --bg-input: #1a2332;
             --bg-group-header: #162032;
             --bg-toolbar-section: #162032;

             --text-primary: #f1f5f9;
             --text-secondary: #cbd5e1;
             --text-muted: #64748b;
             --text-inverse: #ffffff;

             --border-color: #2d3748;
             --border-light: #1e293b;
             --border-dark: #3d4a5c;

             --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
             --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
             --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
             --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
         }

         * {
             margin: 0;
             padding: 0;
             box-sizing: border-box;
         }

         body {
             background-color: var(--bg-app);
             color: var(--text-primary);
             font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
             font-size: 13px;
             overflow-x: hidden;
             transition: background-color var(--transition-normal), color var(--transition-normal);
         }

         ::-webkit-scrollbar {
             width: 8px;
             height: 8px;
         }

         ::-webkit-scrollbar-track {
             background: var(--bg-app);
         }

         ::-webkit-scrollbar-thumb {
             background: var(--border-dark);
             border-radius: 4px;
         }

         ::-webkit-scrollbar-thumb:hover {
             background: var(--text-muted);
         }

         /* HEADER */
         .temenos-header {
             background: var(--bg-header);
             color: var(--text-inverse);
             height: 56px;
             position: fixed;
             top: 0;
             left: 0;
             right: 0;
             z-index: 1030;
             box-shadow: var(--shadow-lg);
             transition: all var(--transition-normal);
         }

         .header-container {
             display: flex;
             align-items: center;
             justify-content: space-between;
             height: 100%;
             padding: 0 20px;
         }

         .logo-section {
             display: flex;
             align-items: center;
             gap: 16px;
             flex: 1;
             min-width: 0;
         }

         .temenos-logo {
             background: rgba(255, 255, 255, 0.15);
             backdrop-filter: blur(10px);
             color: white;
             padding: 6px 14px;
             font-weight: 700;
             font-size: 16px;
             border-radius: var(--radius-md);
             letter-spacing: 1px;
             border: 1px solid rgba(255, 255, 255, 0.2);
             transition: all var(--transition-fast);
             flex-shrink: 0;
         }

         .temenos-logo:hover {
             background: rgba(255, 255, 255, 0.25);
             transform: translateY(-1px);
         }

         .system-info {
             display: flex;
             flex-direction: column;
             flex-shrink: 0;
         }

         .system-title {
             font-size: 15px;
             font-weight: 600;
             letter-spacing: 0.3px;
             line-height: 1.2;
         }

         .system-version {
             font-size: 10px;
             opacity: 0.75;
             font-family: 'JetBrains Mono', monospace;
         }

         .sidebar-toggle-desktop {
             background: rgba(255, 255, 255, 0.1);
             border: 1px solid rgba(255, 255, 255, 0.2);
             color: white;
             width: 36px;
             height: 36px;
             border-radius: var(--radius-md);
             cursor: pointer;
             display: flex;
             align-items: center;
             justify-content: center;
             transition: all var(--transition-fast);
             font-size: 18px;
             flex-shrink: 0;
         }

         .sidebar-toggle-desktop:hover {
             background: rgba(255, 255, 255, 0.25);
             transform: scale(1.05);
         }

         .sidebar-toggle-desktop:active {
             transform: scale(0.95);
         }

         @media (max-width: 991.98px) {
             .sidebar-toggle-desktop {
                 display: none !important;
             }
         }

         .header-menu-wrapper {
             position: relative;
             display: flex;
             align-items: center;
             flex: 1;
             min-width: 0;
             margin-left: 16px;
         }

         .header-menu {
             display: flex;
             gap: 4px;
             overflow-x: hidden;
             overflow-y: hidden;
             scroll-behavior: smooth;
             scrollbar-width: thin;
             white-space: nowrap;
             -webkit-overflow-scrolling: touch;
             padding: 4px 0;
             margin: 0;
             flex: 1;
             min-width: 0;
         }

         .header-menu::-webkit-scrollbar {
             height: 3px;
         }

         .header-menu::-webkit-scrollbar-track {
             background: rgba(255, 255, 255, 0.1);
             border-radius: 10px;
         }

         .header-menu::-webkit-scrollbar-thumb {
             background: rgba(255, 255, 255, 0.3);
             border-radius: 10px;
         }

         .header-menu::-webkit-scrollbar-thumb:hover {
             background: rgba(255, 255, 255, 0.5);
         }

         .menu-nav-btn {
             background: rgba(255, 255, 255, 0.15);
             border: 1px solid rgba(255, 255, 255, 0.2);
             color: white;
             width: 28px;
             height: 32px;
             border-radius: var(--radius-md);
             cursor: pointer;
             display: none;
             align-items: center;
             justify-content: center;
             transition: all var(--transition-fast);
             font-size: 14px;
             flex-shrink: 0;
         }

         .menu-nav-btn:hover {
             background: rgba(255, 255, 255, 0.3);
             transform: scale(1.05);
         }

         .menu-nav-btn:active {
             transform: scale(0.95);
         }

         .menu-nav-prev {
             margin-right: 4px;
         }

         .menu-nav-next {
             margin-left: 4px;
         }

         .menu-item {
             color: rgba(255, 255, 255, 0.85);
             padding: 8px 18px;
             font-size: 13px;
             font-weight: 500;
             cursor: pointer;
             border-radius: var(--radius-md);
             transition: all var(--transition-fast);
             display: flex;
             align-items: center;
             gap: 8px;
             position: relative;
             flex-shrink: 0;
         }

         .menu-item:hover {
             background: rgba(255, 255, 255, 0.15);
             color: white;
         }

         .menu-item.active {
             background: rgba(255, 255, 255, 0.2);
             color: white;
             font-weight: 600;
         }

         .menu-item.active::after {
             content: '';
             position: absolute;
             bottom: -8px;
             left: 50%;
             transform: translateX(-50%);
             width: 30px;
             height: 3px;
             background: white;
             border-radius: 3px 3px 0 0;
         }

         .menu-item i {
             font-size: 14px;
         }

         .header-info {
             display: flex;
             align-items: center;
             gap: 12px;
             font-size: 12px;
             flex-shrink: 0;
         }

         .header-info-box {
             display: flex;
             align-items: center;
             gap: 8px;
             background: rgba(255, 255, 255, 0.1);
             backdrop-filter: blur(10px);
             padding: 6px 14px;
             border-radius: var(--radius-md);
             border: 1px solid rgba(255, 255, 255, 0.15);
             transition: all var(--transition-fast);
         }

         .header-info-box:hover {
             background: rgba(255, 255, 255, 0.18);
         }

         .datetime-display {
             font-family: 'JetBrains Mono', monospace;
             font-weight: 500;
             background: rgba(0, 0, 0, 0.25);
             padding: 6px 12px;
             border-radius: var(--radius-md);
             letter-spacing: 0.5px;
             font-size: 11px;
         }

         .notification-bell,
         .theme-toggle-modern,
         .color-picker-btn {
             position: relative;
             background: rgba(255, 255, 255, 0.1);
             border: 1px solid rgba(255, 255, 255, 0.2);
             color: white;
             width: 36px;
             height: 36px;
             border-radius: var(--radius-md);
             cursor: pointer;
             display: flex;
             align-items: center;
             justify-content: center;
             transition: all var(--transition-fast);
             font-size: 16px;
         }

         .notification-bell:hover,
         .theme-toggle-modern:hover,
         .color-picker-btn:hover {
             background: rgba(255, 255, 255, 0.25);
             transform: scale(1.05);
         }

         .theme-toggle-modern:hover {
             transform: rotate(15deg);
         }

         .color-picker-btn:hover {
             background: linear-gradient(135deg, var(--temenos-primary), var(--temenos-accent));
             transform: rotate(15deg);
         }

         .notification-badge {
             position: absolute;
             top: -5px;
             right: -5px;
             background-color: #dc3545;
             color: white;
             font-size: 10px;
             font-weight: 600;
             min-width: 18px;
             height: 18px;
             border-radius: 50%;
             display: flex;
             align-items: center;
             justify-content: center;
         }

         .btn-logout {
             background: rgba(255, 255, 255, 0.1);
             border: 1px solid rgba(255, 255, 255, 0.2);
             color: white;
             padding: 6px 14px;
             border-radius: var(--radius-md);
             cursor: pointer;
             transition: all var(--transition-fast);
             font-size: 13px;
             display: flex;
             align-items: center;
             gap: 6px;
             text-decoration: none;
         }

         .btn-logout:hover {
             background: var(--temenos-danger);
             border-color: var(--temenos-danger);
             transform: scale(1.05);
             color: white;
         }

         .btn-logout i {
             font-size: 14px;
         }

         /* COLOR PICKER MODAL */
         .color-picker-modal {
             display: none;
             position: fixed;
             top: 0;
             left: 0;
             right: 0;
             bottom: 0;
             background: rgba(0, 0, 0, 0.6);
             backdrop-filter: blur(8px);
             z-index: 2000;
             align-items: center;
             justify-content: center;
             animation: fadeIn 0.3s ease;
         }

         .color-picker-modal.active {
             display: flex;
         }

         .color-picker-content {
             background: var(--bg-window);
             border-radius: var(--radius-lg);
             padding: 30px;
             max-width: 780px;
             width: 95%;
             max-height: 85vh;
             overflow-y: auto;
             box-shadow: var(--shadow-xl);
             animation: slideUp 0.3s ease;
         }

         @keyframes slideUp {
             from {
                 transform: translateY(30px);
                 opacity: 0;
             }

             to {
                 transform: translateY(0);
                 opacity: 1;
             }
         }

         .color-picker-header {
             display: flex;
             justify-content: space-between;
             align-items: center;
             margin-bottom: 24px;
             padding-bottom: 16px;
             border-bottom: 2px solid var(--border-color);
         }

         .color-picker-title {
             font-size: 20px;
             font-weight: 700;
             color: var(--text-primary);
             display: flex;
             align-items: center;
             gap: 10px;
         }

         .color-picker-title i {
             background: linear-gradient(135deg, var(--temenos-primary), var(--temenos-accent));
             -webkit-background-clip: text;
             -webkit-text-fill-color: transparent;
             background-clip: text;
         }

         .color-picker-close {
             background: var(--bg-group-header);
             border: 1px solid var(--border-color);
             width: 32px;
             height: 32px;
             border-radius: 50%;
             cursor: pointer;
             display: flex;
             align-items: center;
             justify-content: center;
             transition: all var(--transition-fast);
             color: var(--text-primary);
         }

         .color-picker-close:hover {
             background: var(--temenos-danger);
             color: white;
             transform: rotate(90deg);
         }

         .theme-section {
             margin-bottom: 28px;
         }

         .theme-section-title {
             font-size: 12px;
             font-weight: 700;
             color: var(--text-secondary);
             margin-bottom: 14px;
             text-transform: uppercase;
             letter-spacing: 1px;
             display: flex;
             align-items: center;
             gap: 10px;
         }

         .theme-section-title i {
             font-size: 16px;
             color: var(--temenos-primary);
         }

         .theme-section-title::after {
             content: '';
             flex: 1;
             height: 1px;
             background: linear-gradient(90deg, var(--border-color), transparent);
             margin-left: 8px;
         }

         .theme-section-title .badge-count {
             background: var(--temenos-primary);
             color: white;
             font-size: 10px;
             padding: 2px 8px;
             border-radius: 10px;
             font-weight: 600;
         }

         .color-grid {
             display: grid;
             grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
             gap: 12px;
         }

         .color-option {
             background: var(--bg-group-header);
             border: 2px solid var(--border-color);
             border-radius: var(--radius-md);
             padding: 14px 10px 12px;
             cursor: pointer;
             transition: all var(--transition-fast);
             text-align: center;
             position: relative;
             overflow: hidden;
         }

         .color-option:hover {
             transform: translateY(-3px);
             box-shadow: var(--shadow-md);
             border-color: var(--temenos-primary);
         }

         .color-option.active {
             border-color: var(--temenos-primary);
             box-shadow: 0 0 0 3px rgba(10, 77, 140, 0.15);
         }

         .color-option.active::after {
             content: '✓';
             position: absolute;
             top: 6px;
             right: 8px;
             font-size: 11px;
             font-weight: bold;
             color: white;
             background: var(--temenos-primary);
             width: 18px;
             height: 18px;
             border-radius: 50%;
             display: flex;
             align-items: center;
             justify-content: center;
             box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
         }

         /* PREVIEW UNICOLORE */
         .color-preview-mono {
             width: 48px;
             height: 48px;
             border-radius: 50%;
             margin: 0 auto 10px;
             border: 3px solid rgba(255, 255, 255, 0.5);
             box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
         }

         /* PREVIEW BICOLORE */
         .color-preview-bi {
             display: flex;
             justify-content: center;
             gap: 6px;
             margin: 0 auto 10px;
             width: 100%;
             max-width: 90px;
             height: 48px;
             align-items: center;
         }

         .color-preview-bi .swatch {
             flex: 1;
             height: 42px;
             border-radius: 8px;
             box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
             border: 2px solid rgba(255, 255, 255, 0.5);
         }

         .color-preview-bi .swatch:first-child {
             transform: rotate(-3deg);
             margin-right: -4px;
             z-index: 2;
         }

         .color-preview-bi .swatch:last-child {
             transform: rotate(3deg);
             margin-left: -4px;
             z-index: 1;
         }

         /* PREVIEW TRICOLORE */
         .color-preview-tri {
             display: flex;
             justify-content: center;
             gap: 4px;
             margin: 0 auto 10px;
             width: 100%;
             max-width: 100px;
             height: 48px;
             align-items: center;
         }

         .color-preview-tri .swatch {
             flex: 1;
             height: 38px;
             border-radius: 6px;
             box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
             border: 2px solid rgba(255, 255, 255, 0.5);
         }

         .color-preview-tri .swatch:nth-child(1) {
             transform: rotate(-4deg);
             margin-right: -3px;
             z-index: 3;
         }

         .color-preview-tri .swatch:nth-child(2) {
             z-index: 2;
         }

         .color-preview-tri .swatch:nth-child(3) {
             transform: rotate(4deg);
             margin-left: -3px;
             z-index: 1;
         }

         /* PREVIEW GRADIENT */
         .color-preview-gradient {
             width: 100%;
             max-width: 100px;
             height: 48px;
             margin: 0 auto 10px;
             border-radius: 10px;
             box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
             border: 2px solid rgba(255, 255, 255, 0.5);
             position: relative;
             overflow: hidden;
         }

         .color-preview-gradient::after {
             content: '';
             position: absolute;
             top: 0;
             left: -100%;
             width: 100%;
             height: 100%;
             background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
             animation: shimmer 3s infinite;
         }

         @keyframes shimmer {
             0% {
                 left: -100%;
             }

             100% {
                 left: 100%;
             }
         }

         .color-name {
             font-size: 10px;
             font-weight: 600;
             text-transform: uppercase;
             letter-spacing: 0.5px;
             color: var(--text-primary);
             line-height: 1.3;
         }

         .palette-tag {
             position: absolute;
             top: 6px;
             left: 8px;
             font-size: 9px;
             font-weight: 700;
             padding: 1px 6px;
             border-radius: 4px;
             background: rgba(0, 0, 0, 0.6);
             color: white;
             letter-spacing: 0.3px;
             z-index: 5;
         }

         /* TOOLBAR */
         .toolbar {
             position: fixed;
             top: 56px;
             left: 0;
             right: 0;
             background: var(--bg-toolbar);
             border-bottom: 1px solid var(--border-color);
             padding: 8px 20px;
             display: flex;
             gap: 6px;
             align-items: center;
             z-index: 1020;
             box-shadow: var(--shadow-sm);
             transition: all var(--transition-normal);
             flex-wrap: nowrap;
             overflow-x: auto;
             overflow-y: hidden;
             min-height: 48px;
             scroll-behavior: smooth;
             scrollbar-width: thin;
         }

         .toolbar::-webkit-scrollbar {
             height: 4px;
         }

         .toolbar::-webkit-scrollbar-track {
             background: transparent;
         }

         .toolbar::-webkit-scrollbar-thumb {
             background: var(--temenos-primary);
             border-radius: 4px;
         }

         .toolbar-context {
             display: flex;
             align-items: center;
             gap: 8px;
             padding: 6px 14px;
             background: var(--bg-toolbar-section);
             border: 1px solid var(--border-color);
             border-radius: var(--radius-md);
             margin-right: 8px;
             font-size: 11px;
             font-weight: 700;
             color: var(--temenos-primary);
             text-transform: uppercase;
             letter-spacing: 0.5px;
             white-space: nowrap;
             flex-shrink: 0;
         }

         .toolbar-context i {
             font-size: 14px;
         }

         .toolbar-btn {
             background: var(--bg-window);
             border: 1px solid var(--border-color);
             padding: 6px 14px;
             font-size: 12px;
             font-weight: 500;
             cursor: pointer;
             border-radius: var(--radius-md);
             display: inline-flex;
             align-items: center;
             gap: 6px;
             color: var(--text-primary);
             transition: all var(--transition-fast);
             white-space: nowrap;
             flex-shrink: 0;
         }

         .toolbar-btn:hover {
             background: var(--temenos-primary);
             border-color: var(--temenos-primary);
             color: white;
             transform: translateY(-1px);
             box-shadow: var(--shadow-md);
         }

         .toolbar-btn:active {
             transform: translateY(0);
         }

         .toolbar-btn i {
             font-size: 14px;
             color: var(--temenos-primary);
             transition: color var(--transition-fast);
         }

         .toolbar-btn:hover i {
             color: white;
         }

         .toolbar-btn.btn-success {
             background: linear-gradient(135deg, var(--temenos-success) 0%, #059669 100%);
             color: white;
             border-color: #059669;
         }

         .toolbar-btn.btn-success i {
             color: white;
         }

         .toolbar-btn.btn-danger {
             background: linear-gradient(135deg, var(--temenos-danger) 0%, #dc2626 100%);
             color: white;
             border-color: #dc2626;
         }

         .toolbar-btn.btn-danger i {
             color: white;
         }

         .toolbar-btn.btn-primary {
             background: linear-gradient(135deg, var(--temenos-primary) 0%, var(--temenos-primary-dark) 100%);
             color: white;
             border-color: var(--temenos-primary-dark);
         }

         .toolbar-btn.btn-primary i {
             color: white;
         }

         .toolbar-separator {
             width: 1px;
             height: 24px;
             background: var(--border-color);
             margin: 0 4px;
             flex-shrink: 0;
         }

         /* LAYOUT */
         .main-layout {
             display: flex;
             margin-top: 104px;
             min-height: calc(100vh - 104px - 36px);
             padding-bottom: 36px;
         }

         /* SIDEBAR */
         .temenos-sidebar {
             width: var(--sidebar-width);
             min-width: var(--sidebar-width);
             background: var(--bg-sidebar);
             border-right: 1px solid var(--border-color);
             overflow-y: auto;
             overflow-x: hidden;
             position: fixed;
             left: 0;
             top: 104px;
             bottom: 36px;
             z-index: 1010;
             transition: transform var(--transition-normal),
                 background var(--transition-normal);
             box-shadow: var(--shadow-sm);
         }

         .menu-tree {
             padding: 12px 8px;
         }

         .menu-group {
             margin-bottom: 6px;
             border-radius: var(--radius-md);
             overflow: hidden;
         }

         .menu-group-header {
             background: var(--bg-group-header);
             border: 1px solid var(--border-color);
             padding: 10px 14px;
             font-weight: 600;
             font-size: 11px;
             cursor: pointer;
             display: flex;
             align-items: center;
             gap: 10px;
             color: var(--text-primary);
             border-radius: var(--radius-md);
             transition: all var(--transition-fast);
             text-transform: uppercase;
             letter-spacing: 0.5px;
         }

         .menu-group-header:hover {
             background: var(--temenos-primary);
             color: white;
             border-color: var(--temenos-primary);
         }

         .menu-group-header:hover i.chevron-icon {
             color: white;
         }

         .menu-group-header i.chevron-icon {
             font-size: 10px;
             transition: transform var(--transition-normal), color var(--transition-fast);
             color: var(--text-muted);
         }

         .menu-group-header.collapsed i.chevron-icon {
             transform: rotate(-90deg);
         }

         .menu-group-header .menu-emoji {
             font-size: 14px;
         }

         .menu-items {
             padding: 6px 0 6px 12px;
             border-left: 2px solid var(--border-color);
             margin-left: 16px;
             margin-top: 4px;
         }

         .menu-group.collapsed .menu-items {
             display: none;
         }

         .menu-link {
             display: flex;
             align-items: center;
             gap: 8px;
             padding: 7px 12px;
             color: var(--text-secondary);
             text-decoration: none;
             cursor: pointer;
             border-radius: var(--radius-sm);
             font-size: 12px;
             font-weight: 500;
             transition: all var(--transition-fast);
             margin-bottom: 2px;
         }

         .menu-link::before {
             content: '';
             width: 4px;
             height: 4px;
             border-radius: 50%;
             background: var(--border-dark);
             transition: all var(--transition-fast);
         }

         .menu-link:hover {
             background: var(--bg-grid-hover);
             color: var(--temenos-primary);
         }

         .menu-link:hover::before {
             background: var(--temenos-primary);
             transform: scale(1.5);
         }

         .menu-link.active {
             background: var(--temenos-primary);
             color: white;
             font-weight: 600;
         }

         .menu-link.active::before {
             background: white;
         }

         .menu-badge {
             margin-left: auto;
             background: var(--temenos-danger);
             color: white;
             padding: 2px 8px;
             border-radius: 10px;
             font-size: 10px;
             font-weight: 700;
             min-width: 20px;
             text-align: center;
         }

         /* CONTENT AREA */
         .content-area {
             margin-left: var(--sidebar-width);
             flex: 1;
             background: var(--bg-content);
             overflow: auto;
             transition: margin-left var(--transition-normal),
                 background var(--transition-normal);
             min-height: calc(100vh - 104px - 36px);
         }

         @media (min-width: 992px) {
             .content-area.sidebar-collapsed {
                 margin-left: 0 !important;
             }

             .temenos-sidebar.sidebar-collapsed {
                 transform: translateX(-100%);
             }
         }

         /* STATUS BAR */
         .status-bar {
             position: fixed;
             bottom: 0;
             left: 0;
             right: 0;
             background: var(--bg-toolbar);
             border-top: 1px solid var(--border-color);
             padding: 8px 20px;
             font-size: 11px;
             display: flex;
             justify-content: space-between;
             align-items: center;
             z-index: 1030;
             transition: all var(--transition-normal);
             box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.03);
         }

         .status-left,
         .status-right {
             display: flex;
             gap: 20px;
             align-items: center;
         }

         .status-item {
             display: flex;
             align-items: center;
             gap: 6px;
             color: var(--text-secondary);
             font-weight: 500;
         }

         .status-item i {
             color: var(--temenos-primary);
         }

         .status-dot {
             width: 8px;
             height: 8px;
             border-radius: 50%;
             background: var(--temenos-success);
             box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
             animation: pulse-dot 2s infinite;
         }

         @keyframes pulse-dot {

             0%,
             100% {
                 box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
             }

             50% {
                 box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.1);
             }
         }

         /* MOBILE */
         .mobile-toggle {
             display: none;
             background: rgba(255, 255, 255, 0.15);
             border: 1px solid rgba(255, 255, 255, 0.2);
             color: white;
             width: 36px;
             height: 36px;
             align-items: center;
             justify-content: center;
             cursor: pointer;
             border-radius: var(--radius-md);
             font-size: 18px;
             transition: all var(--transition-fast);
             flex-shrink: 0;
         }

         .mobile-toggle:hover {
             background: rgba(255, 255, 255, 0.25);
         }

         .sidebar-overlay {
             display: none;
             position: fixed;
             top: 104px;
             left: 0;
             right: 0;
             bottom: 36px;
             background: rgba(0, 0, 0, 0.5);
             z-index: 1005;
             backdrop-filter: blur(4px);
         }

         .sidebar-overlay.active {
             display: block;
             animation: fadeIn 0.3s ease;
         }

         @keyframes fadeIn {
             from {
                 opacity: 0;
             }

             to {
                 opacity: 1;
             }
         }

         @media (max-width: 1200px) {

             .header-info-box.branch-info,
             .header-info-box.user-info {
                 display: none;
             }
         }

         @media (max-width: 991.98px) {
             .temenos-sidebar {
                 transform: translateX(-100%);
                 box-shadow: none;
             }

             .temenos-sidebar.mobile-open {
                 transform: translateX(0);
                 box-shadow: var(--shadow-xl);
             }

             .content-area {
                 margin-left: 0 !important;
             }

             .mobile-toggle {
                 display: flex;
             }

             .header-menu-wrapper {
                 display: none !important;
             }
         }

         @media (max-width: 768px) {
             .datetime-display {
                 display: none;
             }

             .toolbar {
                 padding: 6px 12px;
             }

             .toolbar-btn {
                 padding: 6px 10px;
                 font-size: 11px;
             }

             .toolbar-context {
                 padding: 4px 10px;
                 font-size: 10px;
             }

             .content-area {
                 padding: 12px;
             }

             .status-right {
                 display: none;
             }

             .color-grid {
                 grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
             }

             .color-picker-content {
                 padding: 20px;
             }
         }

         iframe {
             width: 100%;
             height: 100%;
             border: none;
             background: var(--bg-window);
             border-radius: var(--radius-lg);
             transition: opacity 0.3s ease;
         }

         .cbs-logo {
             width: auto;
             height: 62px;
             filter: brightness(1.1);
         }
     </style>
 </head>

 <body>

     <!-- HEADER -->
     <header class="temenos-header">
         <div class="header-container">
             <div class="logo-section">
                 <button class="mobile-toggle" id="mobileToggle" aria-label="Menu">
                     <i class="bi-list"></i>
                 </button>

                 <img src="<?php if (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"])), -1) == "/") {
                                echo (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"])), 0, -1));
                            } else {
                                echo ((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"]));
                            } ?>/assets/logo.png" alt="Logo" class="cbs-logo">

                 <div class="system-info">
                     <div class="system-title">Sutura Group</div>
                     <div class="system-version"><?php if(!empty($_SESSION['role'])){ echo $_SESSION['role']; } ?></div>
                 </div>
                 <button class="sidebar-toggle-desktop" id="sidebarToggleDesktop" title="Afficher/Masquer le menu latéral (Ctrl+B)">
                     <i class="bi-layout-sidebar-inset" id="sidebarToggleIcon"></i>
                 </button>

                 <!-- HEADER MENU -->
                 <div class="header-menu-wrapper me-3">
                     <button class="menu-nav-btn menu-nav-prev" id="menuNavPrev">
                         <i class="bi-chevron-left"></i>
                     </button>
                     <nav class="header-menu" id="headerMenu"></nav>
                     <button class="menu-nav-btn menu-nav-next" id="menuNavNext">
                         <i class="bi-chevron-right"></i>
                     </button>
                 </div>
             </div>

             <div class="header-info">
                 <div class="header-info-box user-info">
                     <i class="bi-person-circle"></i>
                     <span><?php if (!empty($_SESSION['nom_prenom'])) {
                                echo $_SESSION['nom_prenom'];
                            } else {
                                echo 'N/A';
                            } ?></span>
                 </div>
                 <div class="notification-bell" id="notificationBell">
                     <i class="bi-bell-fill"></i>
                     <span class="notification-badge" id="notificationBadge">
                         6
                     </span>
                 </div>
                 <button class="color-picker-btn" id="colorPickerBtn" title="Choisir une palette">
                     <i class="bi-palette-fill"></i>
                 </button>
                 <button class="theme-toggle-modern" id="themeToggle" title="Changer de thème">
                     <i class="bi-moon-stars-fill" id="themeIcon"></i>
                 </button>
                 <a href="<?php if (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"])), -1) == "/") {
                                echo (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"])), 0, -1));
                            } else {
                                echo ((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"]));
                            } ?>/utilisateur/deconnexion" class="btn-logout" title="Se déconnecter">
                     <i class="bi-lock-fill"></i>
                 </a>
             </div>
         </div>
     </header>

     <!-- COLOR PICKER MODAL -->
     <div class="color-picker-modal" id="colorPickerModal">
         <div class="color-picker-content">
             <div class="color-picker-header">
                 <div class="color-picker-title">
                     <i class="bi-palette-fill"></i>
                     Personnalisation des couleurs
                 </div>
                 <button class="color-picker-close" id="colorPickerClose">
                     <i class="bi-x-lg"></i>
                 </button>
             </div>

             <div class="theme-section">
                 <div class="theme-section-title">
                     <i class="bi-circle-fill"></i>
                     Couleurs uniques
                     <span class="badge-count" id="countMono">0</span>
                 </div>
                 <div class="color-grid" id="colorGridMono"></div>
             </div>

             <div class="theme-section">
                 <div class="theme-section-title">
                     <i class="bi-palette"></i>
                     Palettes bicolores
                     <span class="badge-count" id="countBi">0</span>
                 </div>
                 <div class="color-grid" id="colorGridBi"></div>
             </div>

             <div class="theme-section">
                 <div class="theme-section-title">
                     <i class="bi-palette2"></i>
                     Palettes tricolores
                     <span class="badge-count" id="countTri">0</span>
                 </div>
                 <div class="color-grid" id="colorGridTri"></div>
             </div>

             <div class="theme-section">
                 <div class="theme-section-title">
                     <i class="bi-brush-fill"></i>
                     Dégradés spéciaux
                     <span class="badge-count" id="countGradient">0</span>
                 </div>
                 <div class="color-grid" id="colorGridGradient"></div>
             </div>
         </div>
     </div>

     <!-- TOOLBAR -->
     <div class="toolbar" id="toolbar"></div>

     <!-- OVERLAY MOBILE -->
     <div class="sidebar-overlay" id="sidebarOverlay"></div>

     <!-- LAYOUT PRINCIPAL -->
     <div class="main-layout">
         <aside class="temenos-sidebar" id="temenosSidebar">
             <div class="menu-tree" id="sidebarMenu"></div>
         </aside>

         <iframe
             src="<?php if (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"])), -1) == "/") {
                        echo (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"])), 0, -1));
                    } else {
                        echo ((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER["PHP_SELF"]));
                    } ?>/publics/dashboard"
             class="content-area"
             id="contentArea"
             name="contentFrame"
             frameborder="0"
             allowfullscreen>
         </iframe>
     </div>

     <!-- STATUS BAR -->
     <div class="status-bar">
         <div class="status-left">
             <div class="status-item">
                 <span class="status-dot"></span>
                 <span>Connecté</span>
             </div>
             <div class="status-item">
                 <i class="bi-database"></i>
                 <span>Epencia</span>
             </div>
             <div class="status-item">
                 <i class="bi-calendar"></i>
                 <span>Date: <span id="sessionDate"><?php echo date('d/m/Y'); ?></span></span>
             </div>
             <div class="status-item">
                 <i class="bi-clock-history"></i>
                 <span>Session: <span id="sessionTime">14:32:15</span></span>
             </div>
             <div class="status-item">
                 <i class="bi-shield-fill-check"></i>
                 <span>
                     <?= !empty($_SESSION['nom_prenom']) ? htmlspecialchars($_SESSION['nom_prenom']) : 'N/A' ?>
                     (<?= !empty($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'Utilisateur' ?>)
                 </span>
             </div>
         </div>
         <div class="status-right">
             <div class="status-item"><i class="bi-exclamation-triangle-fill text-warning"></i><span>Échéances: 5</span></div>
             <div class="status-item"><i class="bi-exclamation-octagon-fill text-danger"></i><span>Impayés: 2</span></div>
         </div>
     </div>

     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
     <script>
         // =========================================
         // PALETTES DE COULEURS - 4 CATÉGORIES
         // =========================================
         const monoThemes = {
             bleu: {
                 name: 'Bleu Temenos',
                 primary: '#0a4d8c',
                 primaryDark: '#063a6b',
                 primaryLight: '#1a6bb5',
                 accent: '#00a3e0',
                 headerBg: 'linear-gradient(135deg, #0a4d8c 0%, #063a6b 100%)',
                 hoverBg: '#e0f2fe',
                 selectedBg: '#bae6fd'
             },
             vert: {
                 name: 'Vert Émeraude',
                 primary: '#059669',
                 primaryDark: '#047857',
                 primaryLight: '#10b981',
                 accent: '#34d399',
                 headerBg: 'linear-gradient(135deg, #059669 0%, #047857 100%)',
                 hoverBg: '#d1fae5',
                 selectedBg: '#a7f3d0'
             },
             violet: {
                 name: 'Violet Royal',
                 primary: '#7c3aed',
                 primaryDark: '#6d28d9',
                 primaryLight: '#8b5cf6',
                 accent: '#a78bfa',
                 headerBg: 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%)',
                 hoverBg: '#ede9fe',
                 selectedBg: '#ddd6fe'
             },
             rouge: {
                 name: 'Rouge Passion',
                 primary: '#dc2626',
                 primaryDark: '#b91c1c',
                 primaryLight: '#ef4444',
                 accent: '#f87171',
                 headerBg: 'linear-gradient(135deg, #dc2626 0%, #b91c1c 100%)',
                 hoverBg: '#fee2e2',
                 selectedBg: '#fecaca'
             },
             orange: {
                 name: 'Orange Sunset',
                 primary: '#ea580c',
                 primaryDark: '#c2410c',
                 primaryLight: '#f97316',
                 accent: '#fb923c',
                 headerBg: 'linear-gradient(135deg, #ea580c 0%, #c2410c 100%)',
                 hoverBg: '#fed7aa',
                 selectedBg: '#fdba74'
             },
             rose: {
                 name: 'Rose Magnolia',
                 primary: '#db2777',
                 primaryDark: '#be185d',
                 primaryLight: '#ec4899',
                 accent: '#f472b6',
                 headerBg: 'linear-gradient(135deg, #db2777 0%, #be185d 100%)',
                 hoverBg: '#fce7f3',
                 selectedBg: '#fbcfe8'
             },
             cyan: {
                 name: 'Cyan Océan',
                 primary: '#0891b2',
                 primaryDark: '#0e7490',
                 primaryLight: '#06b6d4',
                 accent: '#22d3ee',
                 headerBg: 'linear-gradient(135deg, #0891b2 0%, #0e7490 100%)',
                 hoverBg: '#cffafe',
                 selectedBg: '#a5f3fc'
             },
             indigo: {
                 name: 'Indigo Profond',
                 primary: '#4f46e5',
                 primaryDark: '#4338ca',
                 primaryLight: '#6366f1',
                 accent: '#818cf8',
                 headerBg: 'linear-gradient(135deg, #4f46e5 0%, #4338ca 100%)',
                 hoverBg: '#e0e7ff',
                 selectedBg: '#c7d2fe'
             },
             teal: {
                 name: 'Teal Moderne',
                 primary: '#0d9488',
                 primaryDark: '#0f766e',
                 primaryLight: '#14b8a6',
                 accent: '#2dd4bf',
                 headerBg: 'linear-gradient(135deg, #0d9488 0%, #0f766e 100%)',
                 hoverBg: '#ccfbf1',
                 selectedBg: '#99f6e4'
             },
             slate: {
                 name: 'Ardoise Élégant',
                 primary: '#475569',
                 primaryDark: '#334155',
                 primaryLight: '#64748b',
                 accent: '#94a3b8',
                 headerBg: 'linear-gradient(135deg, #475569 0%, #334155 100%)',
                 hoverBg: '#f1f5f9',
                 selectedBg: '#e2e8f0'
             }
         };

         const biThemes = {
             'bleu-orange': {
                 name: 'Bleu & Orange',
                 primary: '#0a4d8c',
                 primaryDark: '#063a6b',
                 primaryLight: '#1a6bb5',
                 secondary: '#ea580c',
                 accent: '#f59e0b',
                 headerBg: 'linear-gradient(135deg, #0a4d8c 0%, #ea580c 100%)',
                 hoverBg: '#e0f2fe',
                 selectedBg: '#bae6fd'
             },
             'vert-or': {
                 name: 'Vert & Or',
                 primary: '#059669',
                 primaryDark: '#047857',
                 primaryLight: '#10b981',
                 secondary: '#ca8a04',
                 accent: '#facc15',
                 headerBg: 'linear-gradient(135deg, #059669 0%, #ca8a04 100%)',
                 hoverBg: '#d1fae5',
                 selectedBg: '#a7f3d0'
             },
             'rose-rouge': {
                 name: 'Rose & Rouge',
                 primary: '#db2777',
                 primaryDark: '#be185d',
                 primaryLight: '#ec4899',
                 secondary: '#dc2626',
                 accent: '#f472b6',
                 headerBg: 'linear-gradient(135deg, #db2777 0%, #dc2626 100%)',
                 hoverBg: '#fce7f3',
                 selectedBg: '#fbcfe8'
             },
             'violet-cyan': {
                 name: 'Violet & Cyan',
                 primary: '#7c3aed',
                 primaryDark: '#6d28d9',
                 primaryLight: '#8b5cf6',
                 secondary: '#0891b2',
                 accent: '#06b6d4',
                 headerBg: 'linear-gradient(135deg, #7c3aed 0%, #0891b2 100%)',
                 hoverBg: '#ede9fe',
                 selectedBg: '#ddd6fe'
             },
             'indigo-rose': {
                 name: 'Indigo & Rose',
                 primary: '#4f46e5',
                 primaryDark: '#4338ca',
                 primaryLight: '#6366f1',
                 secondary: '#db2777',
                 accent: '#f472b6',
                 headerBg: 'linear-gradient(135deg, #4f46e5 0%, #db2777 100%)',
                 hoverBg: '#e0e7ff',
                 selectedBg: '#c7d2fe'
             },
             'teal-ambre': {
                 name: 'Teal & Ambre',
                 primary: '#0d9488',
                 primaryDark: '#0f766e',
                 primaryLight: '#14b8a6',
                 secondary: '#d97706',
                 accent: '#fbbf24',
                 headerBg: 'linear-gradient(135deg, #0d9488 0%, #d97706 100%)',
                 hoverBg: '#ccfbf1',
                 selectedBg: '#99f6e4'
             }
         };

         const triThemes = {
             'tricolore-france': {
                 name: '🇫🇷 France',
                 primary: '#0a4d8c',
                 primaryDark: '#063a6b',
                 primaryLight: '#1a6bb5',
                 secondary: '#ffffff',
                 tertiary: '#dc2626',
                 accent: '#f59e0b',
                 headerBg: 'linear-gradient(135deg, #0a4d8c 0%, #ffffff 50%, #dc2626 100%)',
                 hoverBg: '#e0f2fe',
                 selectedBg: '#bae6fd'
             },
             'tricolore-ivoirien': {
                 name: '🇨🇮 Côte d\'Ivoire',
                 primary: '#ea580c',
                 primaryDark: '#c2410c',
                 primaryLight: '#f97316',
                 secondary: '#ffffff',
                 tertiary: '#059669',
                 accent: '#fbbf24',
                 headerBg: 'linear-gradient(135deg, #ea580c 0%, #ffffff 50%, #059669 100%)',
                 hoverBg: '#fed7aa',
                 selectedBg: '#fdba74'
             },
             'tricolore-allemand': {
                 name: '🇩🇪 Allemagne',
                 primary: '#1f2937',
                 primaryDark: '#111827',
                 primaryLight: '#374151',
                 secondary: '#dc2626',
                 tertiary: '#ca8a04',
                 accent: '#fbbf24',
                 headerBg: 'linear-gradient(135deg, #1f2937 0%, #dc2626 50%, #ca8a04 100%)',
                 hoverBg: '#e5e7eb',
                 selectedBg: '#d1d5db'
             },
             'tricolore-italien': {
                 name: '🇮🇹 Italie',
                 primary: '#059669',
                 primaryDark: '#047857',
                 primaryLight: '#10b981',
                 secondary: '#ffffff',
                 tertiary: '#dc2626',
                 accent: '#f59e0b',
                 headerBg: 'linear-gradient(135deg, #059669 0%, #ffffff 50%, #dc2626 100%)',
                 hoverBg: '#d1fae5',
                 selectedBg: '#a7f3d0'
             },
             'tricolore-belge': {
                 name: '🇧🇪 Belgique',
                 primary: '#1f2937',
                 primaryDark: '#111827',
                 primaryLight: '#374151',
                 secondary: '#facc15',
                 tertiary: '#dc2626',
                 accent: '#f59e0b',
                 headerBg: 'linear-gradient(135deg, #1f2937 0%, #facc15 50%, #dc2626 100%)',
                 hoverBg: '#fef3c7',
                 selectedBg: '#fde68a'
             },
             'arc-en-ciel': {
                 name: '🌈 Arc-en-ciel',
                 primary: '#dc2626',
                 primaryDark: '#b91c1c',
                 primaryLight: '#ef4444',
                 secondary: '#facc15',
                 tertiary: '#059669',
                 accent: '#7c3aed',
                 headerBg: 'linear-gradient(135deg, #dc2626 0%, #facc15 33%, #059669 66%, #7c3aed 100%)',
                 hoverBg: '#fee2e2',
                 selectedBg: '#fecaca'
             }
         };

         const gradientThemes = {
             'gradient-sunset': {
                 name: '🌅 Coucher de Soleil',
                 primary: '#ea580c',
                 primaryDark: '#c2410c',
                 primaryLight: '#f97316',
                 secondary: '#db2777',
                 accent: '#7c3aed',
                 headerBg: 'linear-gradient(135deg, #ea580c 0%, #db2777 50%, #7c3aed 100%)',
                 hoverBg: '#fed7aa',
                 selectedBg: '#fdba74'
             },
             'gradient-aurora': {
                 name: '🌌 Aurore Boréale',
                 primary: '#059669',
                 primaryDark: '#047857',
                 primaryLight: '#10b981',
                 secondary: '#7c3aed',
                 accent: '#0891b2',
                 headerBg: 'linear-gradient(135deg, #059669 0%, #7c3aed 50%, #0891b2 100%)',
                 hoverBg: '#d1fae5',
                 selectedBg: '#a7f3d0'
             },
             'gradient-ocean': {
                 name: '🌊 Océan Profond',
                 primary: '#0a4d8c',
                 primaryDark: '#063a6b',
                 primaryLight: '#1a6bb5',
                 secondary: '#0d9488',
                 accent: '#06b6d4',
                 headerBg: 'linear-gradient(135deg, #0a4d8c 0%, #0d9488 50%, #0891b2 100%)',
                 hoverBg: '#e0f2fe',
                 selectedBg: '#bae6fd'
             },
             'gradient-fire': {
                 name: '🔥 Feu Ardent',
                 primary: '#dc2626',
                 primaryDark: '#b91c1c',
                 primaryLight: '#ef4444',
                 secondary: '#ea580c',
                 accent: '#f59e0b',
                 headerBg: 'linear-gradient(135deg, #dc2626 0%, #ea580c 50%, #f59e0b 100%)',
                 hoverBg: '#fee2e2',
                 selectedBg: '#fecaca'
             },
             'gradient-candy': {
                 name: '🍬 Bonbon',
                 primary: '#ec4899',
                 primaryDark: '#be185d',
                 primaryLight: '#f472b6',
                 secondary: '#a78bfa',
                 accent: '#fbcfe8',
                 headerBg: 'linear-gradient(135deg, #ec4899 0%, #a78bfa 50%, #fbcfe8 100%)',
                 hoverBg: '#fce7f3',
                 selectedBg: '#fbcfe8'
             },
             'gradient-forest': {
                 name: '🌲 Forêt',
                 primary: '#166534',
                 primaryDark: '#14532d',
                 primaryLight: '#15803d',
                 secondary: '#854d0e',
                 accent: '#ca8a04',
                 headerBg: 'linear-gradient(135deg, #166534 0%, #15803d 50%, #854d0e 100%)',
                 hoverBg: '#dcfce7',
                 selectedBg: '#bbf7d0'
             }
         };

         // =========================================
         // CONFIGURATION UNIFIÉE
         // =========================================
         const menuConfig = [

             {
                 key: 'accueil',
                 title: 'ACCUEIL',
                 icon: 'bi-speedometer2',
                 emoji: '📊',
                 items: [{
                         icon: 'bi-speedometer2',
                         label: 'Tableau de bord',
                         url: '/publics/dashboard'
                     },
                     {
                         icon: 'bi-person-badge',
                         label: 'Mon profil',
                         url: '/utilisateur/profil'
                     }
                 ]
             },

             {
                 key: 'acteur',
                 title: 'ACTEURS',
                 icon: 'bi-people-fill',
                 emoji: '👥',
                 items: [{
                         icon: 'bi-table',
                         label: 'Utilisateurs',
                         url: '/utilisateur/gestion'
                     },
                    {
                         icon: 'bi-speedometer2',
                         label: 'Clients',
                         url: '/contact/client'
                     },
                     {
                         icon: 'bi-person-badge',
                         label: 'Fournisseurs',
                         url: '/contact/fournisseur'
                     }
                 ]
             },
             {
                 key: 'article',
                 title: 'ARTICLES',
                 icon: 'bi-box-seam',
                 emoji: '📦',
                 items: [{
                         icon: 'bi-collection',
                         label: 'Catégories',
                         url: '/categorie/gestion'
                     },
                     {
                         icon: 'bi-list-ul',
                         label: 'Lot produit',
                         url: '/lot/gestion'
                     },

                     {
                         icon: 'bi-bar-chart',
                         label: 'Produits',
                         url: '/produit/gestion'
                     },

                     {
                         icon: 'bi-list-ul',
                         label: 'Prix',
                         url: '/prix/gestion'
                     }
                 ]
             },
             {
                 key: 'Stock',
                 title: 'STOCK',
                 icon: 'bi-box',
                 emoji: '📚',
                 items: [
                    {
                         icon: 'bi-list-ul',
                         label: 'Entree de stock',
                         url: '/produit/stockEntree'
                     },
                    {
                         icon: 'bi-list-ul',
                         label: 'Sortie de stock',
                         url: '/produit/stockSortie'
                     },
                    {
                         icon: 'bi-list-ul',
                         label: 'Transfert du stock',
                         url: '/commande/transfert'
                     },
                     {
                         icon: 'bi-list-ul',
                         label: 'Ajustement du stock',
                         url: '/produit/ajustement'
                     },
                     {
                         icon: 'bi-bar-chart',
                         label: 'Stock disponible',
                         url: '/produit/stockDisponible'
                     },
                     {
                         icon: 'bi-bar-chart',
                         label: 'Alerte stock',
                         url: '/produit/stockAlerte'
                     },
                     {
                         icon: 'bi-bar-chart',
                         label: 'Rupture de stock',
                         url: '/produit/stockRupture'
                     }

                 ]
             },
             {
                 key: 'Vente',
                 title: 'VENTES',
                 icon: 'bi-cart',
                 emoji: '🛒',
                 items: [{
                         icon: 'bi-list-ul',
                         label: 'Vente au Comptoir',
                         url: '/publics/vente'
                     },

                     {
                         icon: 'bi-list-ul',
                         label: 'Suivi de vente',
                         url: '/commande/vente'
                     },
                 ]
             },

             {
                 key: 'achat',
                 title: 'ACHATS',
                 icon: 'bi-box-arrow-in-down',
                 emoji: '🛍️',
                 items: [{
                         icon: 'bi-list-ul',
                         label: 'Achat fournisseur',
                         url: '/commande/achat'
                     },

                     {
                         icon: 'bi-list-ul',
                         label: 'Suivi achat',
                         url: '/commande/suiviAchat'
                     },

                 ]
             },

             {
                 key: 'facture',
                 title: 'FATURES',
                 icon: 'bi-cash',
                 emoji: '🧾',
                 items: [{
                         icon: 'bi-list-ul',
                         label: 'Factures clients',
                         url: '/facture/gestion'
                     },

                     {
                         icon: 'bi-list-ul',
                         label: 'Reglement facture',
                         url: '/facture/reglement'
                     },

                 ]
             },
             {
                 key: 'tresorerie',
                 title: 'TRESORERIE',
                 icon: 'bi-graph-up',
                 emoji: '💰',
                 items: [{
                         icon: 'bi-pin-map',
                         label: 'Caisse',
                         url: '/caisse/gestion'
                     },
                     {
                         icon: 'bi-list-ul',
                         label: 'Depenses',
                         url: '/depense/gestion'
                     },
                     {
                         icon: 'bi-eye',
                         label: 'Transactions',
                         url: '/transaction/gestion'
                     }
                 ]
             },

             {
                 key: 'configuration',
                 title: 'CONFIGURATIONS',
                 icon: 'bi-clipboard-check',
                 emoji: '🛠️',
                 items: [{
                         icon: 'bi-geo',
                         label: 'Boutiques',
                         url: '/boutique/gestion'
                     },

                     {
                         icon: 'bi-plus-circle',
                         label: 'Statuts',
                         url: '/statut/gestion'
                     },
                     {
                         icon: 'bi-envelope',
                         label: 'Taxes',
                         url: '/taxe/gestion'
                     }

                 ]
             },

             {
    key: 'Rapport',
    title: 'RAPPORTS',
    icon: 'bi-clock-history',
    emoji: '📈',
    items: [
        { icon: 'bi-people', label: 'Situation des Clients', url: '/rapport/situationClient' },
        { icon: 'bi-wallet2', label: 'Compte de Trésorerie', url: '/rapport/compteTresorerie' },
        { icon: 'bi-bar-chart-line', label: 'Résumé des Ventes', url: '/rapport/resumeVente' },
        { icon: 'bi-cart-check', label: 'Résumé des Achats', url: '/rapport/resumeAchat' },
        { icon: 'bi-trophy', label: 'Performance des Vendeurs', url: '/rapport/performanceVendeur' },
        { icon: 'bi-percent', label: 'Marge Bénéficiaire', url: '/rapport/margeBeneficiaire' },
        { icon: 'bi-graph-up-arrow', label: 'Rentabilité des Ventes', url: '/rapport/rentabiliteVente' },
        { icon: 'bi-pie-chart', label: 'Chiffre d\'Affaires', url: '/rapport/chiffreAffaire' },
        { icon: 'bi-arrow-left-right', label: 'Mouvement de Stock', url: '/rapport/mouvementStock' },
        { icon: 'bi-file-earmark-text', label: 'Factures Clients', url: '/rapport/factureClient' },
        { icon: 'bi-file-earmark-invoice', label: 'Factures Fournisseurs', url: '/rapport/factureFournisseur' },
        { icon: 'bi-credit-card-2-front', label: 'Transactions Clients', url: '/rapport/transactionClient' }
    ]
}



         ];

         // =========================================
         // RÉFÉRENCE À L'IFRAME
         // =========================================
         // =========================================
         // BASE URL - RACINE DE VOTRE APPLICATION
         // =========================================
         const BASE_URL = '<?php
                            $proto = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") ? "https://" : "http://";
                            $host = $_SERVER["HTTP_HOST"];
                            $dir = dirname($_SERVER["PHP_SELF"]);
                            $dir = ($dir === "/" || $dir === "\\") ? "" : rtrim($dir, "/\\");
                            echo $proto . $host . $dir;
                            ?>';

         // =========================================
         // RÉFÉRENCE À L'IFRAME
         // =========================================
         const contentFrame = document.getElementById('contentArea');

         // =========================================
         // FONCTION DE CHARGEMENT DANS L'IFRAME
         // =========================================
         function loadPage(url) {
             if (!url || !contentFrame) return;

             let fullUrl = url;

             // Si l'URL est relative (commence par /), on préfixe avec BASE_URL
             if (url.startsWith('/')) {
                 fullUrl = BASE_URL + url;
             }
             // Si l'URL ne contient pas de protocole, on ajoute BASE_URL
             else if (!url.startsWith('http://') && !url.startsWith('https://')) {
                 fullUrl = BASE_URL + '/' + url.replace(/^\//, '');
             }

             contentFrame.style.opacity = '0';
             contentFrame.src = fullUrl;
             contentFrame.onload = () => {
                 contentFrame.style.opacity = '1';
             };
         }

         // =========================================
         // GESTION DES COULEURS - 4 CATÉGORIES
         // =========================================
         function applyColorTheme(themeKey, category) {
             let theme = null;
             if (category === 'mono') theme = monoThemes[themeKey];
             else if (category === 'bi') theme = biThemes[themeKey];
             else if (category === 'tri') theme = triThemes[themeKey];
             else if (category === 'gradient') theme = gradientThemes[themeKey];

             if (!theme) return;

             const root = document.documentElement;
             root.style.setProperty('--temenos-primary', theme.primary);
             root.style.setProperty('--temenos-primary-dark', theme.primaryDark);
             root.style.setProperty('--temenos-primary-light', theme.primaryLight);
             root.style.setProperty('--temenos-accent', theme.accent);
             root.style.setProperty('--bg-header', theme.headerBg);
             root.style.setProperty('--bg-window-header', theme.headerBg);
             root.style.setProperty('--bg-grid-hover', theme.hoverBg);
             root.style.setProperty('--bg-grid-selected', theme.selectedBg);
             root.style.setProperty('--text-accent', theme.primary);

             localStorage.setItem('temenos-color-theme', JSON.stringify({
                 key: themeKey,
                 category
             }));

             document.querySelectorAll('.color-option').forEach(opt => {
                 opt.classList.remove('active');
                 if (opt.dataset.theme === themeKey && opt.dataset.category === category) {
                     opt.classList.add('active');
                 }
             });
         }

         function initColorPicker() {
             const savedData = JSON.parse(localStorage.getItem('temenos-color-theme') || '{"key":"bleu","category":"mono"}');

             // UNICOLORES
             let htmlMono = '';
             Object.keys(monoThemes).forEach(key => {
                 const theme = monoThemes[key];
                 const isActive = savedData.category === 'mono' && savedData.key === key ? 'active' : '';
                 htmlMono += `
                <div class="color-option ${isActive}" data-theme="${key}" data-category="mono">
                    <div class="color-preview-mono" style="background: ${theme.primary}"></div>
                    <div class="color-name">${theme.name}</div>
                </div>
            `;
             });
             document.getElementById('colorGridMono').innerHTML = htmlMono;
             document.getElementById('countMono').textContent = Object.keys(monoThemes).length;

             // BICOLORES
             let htmlBi = '';
             Object.keys(biThemes).forEach(key => {
                 const theme = biThemes[key];
                 const isActive = savedData.category === 'bi' && savedData.key === key ? 'active' : '';
                 htmlBi += `
                <div class="color-option ${isActive}" data-theme="${key}" data-category="bi">
                    <span class="palette-tag">2</span>
                    <div class="color-preview-bi">
                        <div class="swatch" style="background: ${theme.primary}"></div>
                        <div class="swatch" style="background: ${theme.secondary}"></div>
                    </div>
                    <div class="color-name">${theme.name}</div>
                </div>
            `;
             });
             document.getElementById('colorGridBi').innerHTML = htmlBi;
             document.getElementById('countBi').textContent = Object.keys(biThemes).length;

             // TRICOLORES
             let htmlTri = '';
             Object.keys(triThemes).forEach(key => {
                 const theme = triThemes[key];
                 const isActive = savedData.category === 'tri' && savedData.key === key ? 'active' : '';
                 htmlTri += `
                <div class="color-option ${isActive}" data-theme="${key}" data-category="tri">
                    <span class="palette-tag">3</span>
                    <div class="color-preview-tri">
                        <div class="swatch" style="background: ${theme.primary}"></div>
                        <div class="swatch" style="background: ${theme.secondary}"></div>
                        <div class="swatch" style="background: ${theme.tertiary}"></div>
                    </div>
                    <div class="color-name">${theme.name}</div>
                </div>
            `;
             });
             document.getElementById('colorGridTri').innerHTML = htmlTri;
             document.getElementById('countTri').textContent = Object.keys(triThemes).length;

             // GRADIENTS
             let htmlGradient = '';
             Object.keys(gradientThemes).forEach(key => {
                 const theme = gradientThemes[key];
                 const isActive = savedData.category === 'gradient' && savedData.key === key ? 'active' : '';
                 htmlGradient += `
                <div class="color-option ${isActive}" data-theme="${key}" data-category="gradient">
                    <span class="palette-tag">∞</span>
                    <div class="color-preview-gradient" style="background: ${theme.headerBg}"></div>
                    <div class="color-name">${theme.name}</div>
                </div>
            `;
             });
             document.getElementById('colorGridGradient').innerHTML = htmlGradient;
             document.getElementById('countGradient').textContent = Object.keys(gradientThemes).length;

             // Appliquer le thème sauvegardé
             applyColorTheme(savedData.key, savedData.category);

             // Événements
             document.querySelectorAll('.color-option').forEach(option => {
                 option.addEventListener('click', () => {
                     applyColorTheme(option.dataset.theme, option.dataset.category);
                 });
             });
         }

         // Modal Color Picker
         const colorPickerBtn = document.getElementById('colorPickerBtn');
         const colorPickerModal = document.getElementById('colorPickerModal');
         const colorPickerClose = document.getElementById('colorPickerClose');

         if (colorPickerBtn) {
             colorPickerBtn.addEventListener('click', () => {
                 colorPickerModal.classList.add('active');
             });
         }

         if (colorPickerClose) {
             colorPickerClose.addEventListener('click', () => {
                 colorPickerModal.classList.remove('active');
             });
         }

         if (colorPickerModal) {
             colorPickerModal.addEventListener('click', (e) => {
                 if (e.target === colorPickerModal) {
                     colorPickerModal.classList.remove('active');
                 }
             });
         }

         // =========================================
         // GÉNÉRATION DE LA TOOLBAR
         // =========================================
         function renderToolbar(moduleKey) {
             const module = menuConfig.find(m => m.key === moduleKey);
             const toolbar = document.getElementById('toolbar');
             if (!module) return;

             toolbar.style.opacity = '0';
             toolbar.style.transform = 'translateY(-5px)';

             setTimeout(() => {
                 let html = `
                <div class="toolbar-context">
                    <i class="bi ${module.icon}"></i>
                    <span>Module: ${module.title}</span>
                </div>
            `;

                 module.items.forEach((item, index) => {
                     if (item.sep) {
                         html += `<div class="toolbar-separator"></div>`;
                     } else {
                         let classes = 'toolbar-btn';
                         if (item.primary) classes += ' btn-primary';
                         if (item.success) classes += ' btn-success';
                         if (item.danger) classes += ' btn-danger';

                         const icon = item.icon || 'bi-dot';
                         const urlAttr = item.url ? `data-url="${item.url}"` : '';
                         html += `
                        <button class="${classes}" style="animation-delay: ${index * 0.03}s" title="${item.label}" ${urlAttr}>
                            <i class="bi ${icon}"></i>
                            <span>${item.label}</span>
                        </button>
                    `;
                     }
                 });

                 toolbar.innerHTML = html;
                 toolbar.scrollLeft = 0;

                 // Événements pour les boutons de la toolbar
                 document.querySelectorAll('.toolbar-btn[data-url]').forEach(btn => {
                     btn.addEventListener('click', function(e) {
                         e.preventDefault();
                         const url = this.getAttribute('data-url');
                         if (url) loadPage(url);
                     });
                 });

                 toolbar.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                 toolbar.style.opacity = '1';
                 toolbar.style.transform = 'translateY(0)';
             }, 150);
         }

         // =========================================
         // GÉNÉRATION DU SIDEBAR
         // =========================================
         function renderSidebar() {
             const sidebarMenu = document.getElementById('sidebarMenu');
             let html = '';

             menuConfig.forEach(module => {
                 html += `
                <div class="menu-group" data-module="${module.key}">
                    <div class="menu-group-header">
                        <i class="bi-chevron-down chevron-icon"></i>
                        <span class="menu-emoji">${module.emoji}</span>
                        <span>${module.title}</span>
                    </div>
                    <div class="menu-items">
            `;

                 module.items.forEach(item => {
                     if (!item.sep && item.label) {
                         html += `
                        <a href="#" data-url="${item.url || '#'}" class="menu-link">${item.label}</a>
                    `;
                     }
                 });

                 html += `
                    </div>
                </div>
            `;
             });

             sidebarMenu.innerHTML = html;

             // Toggle des groupes du sidebar
             document.querySelectorAll('.menu-group-header').forEach(header => {
                 header.addEventListener('click', (e) => {
                     e.stopPropagation();
                     const group = header.parentElement;
                     group.classList.toggle('collapsed');
                     const icon = header.querySelector('.chevron-icon');
                     if (group.classList.contains('collapsed')) {
                         icon.classList.add('collapsed');
                     } else {
                         icon.classList.remove('collapsed');
                     }
                 });
             });

             // Événements pour les liens du sidebar
             document.querySelectorAll('.menu-link').forEach(link => {
                 link.addEventListener('click', function(e) {
                     e.preventDefault();

                     // Désactiver tous les liens
                     document.querySelectorAll('.menu-link').forEach(l => l.classList.remove('active'));
                     this.classList.add('active');

                     const url = this.getAttribute('data-url');
                     if (url && url !== '#') loadPage(url);

                     // Fermer le sidebar en mode mobile
                     if (window.innerWidth < 992) {
                         sidebar.classList.remove('mobile-open');
                         overlay.classList.remove('active');
                     }
                 });
             });
         }

         // =========================================
         // GÉNÉRATION DU MENU HEADER
         // =========================================
         function renderHeaderMenu() {
             const headerMenu = document.getElementById('headerMenu');
             let html = '';
             let first = true;

             menuConfig.forEach(module => {
                 html += `
                <div class="menu-item ${first ? 'active' : ''}" data-module="${module.key}">
                    <i class="bi ${module.icon}"></i>
                    <span>${module.title}</span>
                </div>
            `;
                 first = false;
             });

             headerMenu.innerHTML = html;

             // Événements pour les items du header menu
             document.querySelectorAll('#headerMenu .menu-item').forEach(item => {
                 item.addEventListener('click', function() {
                     document.querySelectorAll('#headerMenu .menu-item').forEach(i => i.classList.remove('active'));
                     this.classList.add('active');
                     const moduleKey = this.getAttribute('data-module');
                     if (moduleKey) {
                         renderToolbar(moduleKey);
                     }
                 });
             });

             // Activer le premier menu par défaut
             const firstMenuItem = document.querySelector('#headerMenu .menu-item');
             if (firstMenuItem) {
                 const defaultModule = firstMenuItem.getAttribute('data-module');
                 if (defaultModule) renderToolbar(defaultModule);
             }
         }

         // =========================================
         // GESTION DU THÈME (CLAIR/SOMBRE)
         // =========================================
         const themeToggle = document.getElementById('themeToggle');
         const themeIcon = document.getElementById('themeIcon');
         const htmlElement = document.documentElement;

         function getPreferredTheme() {
             const saved = localStorage.getItem('temenos-modern-theme');
             if (saved) return saved;
             return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
         }

         function setTheme(theme) {
             htmlElement.setAttribute('data-theme', theme);
             localStorage.setItem('temenos-modern-theme', theme);
             themeIcon.className = theme === 'dark' ? 'bi-sun-fill' : 'bi-moon-stars-fill';
         }

         setTheme(getPreferredTheme());
         themeToggle.addEventListener('click', () => {
             const current = htmlElement.getAttribute('data-theme');
             setTheme(current === 'light' ? 'dark' : 'light');
         });

         // =========================================
         // HORLOGE
         // =========================================
         function updateDateTime() {
             const now = new Date();
             const dateStr = now.toLocaleDateString('fr-FR');
             const timeStr = now.toLocaleTimeString('fr-FR');
             const datetimeElem = document.getElementById('datetime');
             const sessionTimeElem = document.getElementById('sessionTime');
             if (datetimeElem) datetimeElem.textContent = `${dateStr} ${timeStr}`;
             if (sessionTimeElem) sessionTimeElem.textContent = timeStr;
         }
         setInterval(updateDateTime, 1000);
         updateDateTime();

         // =========================================
         // GESTION DU SCROLL HORIZONTAL DU MENU HEADER
         // =========================================
         const headerMenu = document.getElementById('headerMenu');
         const menuNavPrev = document.getElementById('menuNavPrev');
         const menuNavNext = document.getElementById('menuNavNext');

         function updateMenuNavButtons() {
             if (!headerMenu) return;

             const hasScroll = headerMenu.scrollWidth > headerMenu.clientWidth;

             if (hasScroll) {
                 menuNavPrev.style.display = 'flex';
                 menuNavNext.style.display = 'flex';

                 const isAtStart = headerMenu.scrollLeft <= 5;
                 const isAtEnd = headerMenu.scrollLeft + headerMenu.clientWidth >= headerMenu.scrollWidth - 5;

                 menuNavPrev.style.opacity = isAtStart ? '0.5' : '1';
                 menuNavPrev.style.cursor = isAtStart ? 'not-allowed' : 'pointer';

                 menuNavNext.style.opacity = isAtEnd ? '0.5' : '1';
                 menuNavNext.style.cursor = isAtEnd ? 'not-allowed' : 'pointer';
             } else {
                 menuNavPrev.style.display = 'none';
                 menuNavNext.style.display = 'none';
             }
         }

         function scrollMenu(direction) {
             if (!headerMenu) return;

             const scrollAmount = 200;
             const newScrollLeft = headerMenu.scrollLeft + (direction === 'next' ? scrollAmount : -scrollAmount);

             headerMenu.scrollTo({
                 left: newScrollLeft,
                 behavior: 'smooth'
             });

             setTimeout(updateMenuNavButtons, 300);
         }

         if (menuNavPrev) {
             menuNavPrev.addEventListener('click', () => scrollMenu('prev'));
         }

         if (menuNavNext) {
             menuNavNext.addEventListener('click', () => scrollMenu('next'));
         }

         if (headerMenu) {
             headerMenu.addEventListener('scroll', updateMenuNavButtons);
             window.addEventListener('resize', () => {
                 setTimeout(updateMenuNavButtons, 100);
             });
             setTimeout(updateMenuNavButtons, 100);
         }

         // =========================================
         // GESTION SIDEBAR (MOBILE + DESKTOP)
         // =========================================
         const mobileToggle = document.getElementById('mobileToggle');
         const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');
         const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
         const sidebar = document.getElementById('temenosSidebar');
         const contentArea = document.getElementById('contentArea');
         const overlay = document.getElementById('sidebarOverlay');

         const MOBILE_BREAKPOINT = 992;

         function isMobile() {
             return window.innerWidth < MOBILE_BREAKPOINT;
         }

         function updateDesktopToggleIcon() {
             if (sidebar.classList.contains('sidebar-collapsed')) {
                 sidebarToggleIcon.className = 'bi-layout-sidebar';
             } else {
                 sidebarToggleIcon.className = 'bi-layout-sidebar-inset';
             }
         }

         function toggleDesktopSidebar() {
             const isCollapsed = sidebar.classList.toggle('sidebar-collapsed');
             contentArea.classList.toggle('sidebar-collapsed', isCollapsed);
             localStorage.setItem('temenos-sidebar-collapsed', isCollapsed);
             updateDesktopToggleIcon();
         }

         function initDesktopSidebar() {
             if (!isMobile()) {
                 const savedState = localStorage.getItem('temenos-sidebar-collapsed');
                 if (savedState === 'true') {
                     sidebar.classList.add('sidebar-collapsed');
                     contentArea.classList.add('sidebar-collapsed');
                 }
                 updateDesktopToggleIcon();
             }
         }

         if (sidebarToggleDesktop) {
             sidebarToggleDesktop.addEventListener('click', toggleDesktopSidebar);
         }
         initDesktopSidebar();

         if (mobileToggle) {
             mobileToggle.addEventListener('click', () => {
                 sidebar.classList.toggle('mobile-open');
                 overlay.classList.toggle('active');
             });
         }

         if (overlay) {
             overlay.addEventListener('click', () => {
                 sidebar.classList.remove('mobile-open');
                 overlay.classList.remove('active');
             });
         }

         // =========================================
         // SCROLL HORIZONTAL TOOLBAR
         // =========================================
         const toolbar = document.getElementById('toolbar');
         if (toolbar) {
             toolbar.addEventListener('wheel', (e) => {
                 if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
                     if (toolbar.scrollWidth > toolbar.clientWidth) {
                         e.preventDefault();
                         toolbar.scrollLeft += e.deltaY;
                     }
                 }
             }, {
                 passive: false
             });
         }

         // =========================================
         // KEYBOARD SHORTCUTS
         // =========================================
         document.addEventListener('keydown', (e) => {
             if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                 e.preventDefault();
                 const firstInput = document.querySelector('.form-control-temenos');
                 if (firstInput) firstInput.focus();
             }
             if (e.key === 'Escape') {
                 if (sidebar.classList.contains('mobile-open')) {
                     sidebar.classList.remove('mobile-open');
                     overlay.classList.remove('active');
                 }
                 if (colorPickerModal.classList.contains('active')) {
                     colorPickerModal.classList.remove('active');
                 }
             }
             if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                 e.preventDefault();
                 if (!isMobile() && sidebarToggleDesktop) {
                     toggleDesktopSidebar();
                 }
             }
         });

         // =========================================
         // NOTIFICATIONS
         // =========================================
         const notificationBell = document.getElementById('notificationBell');
         if (notificationBell) {
             notificationBell.addEventListener('click', () => {
                 loadPage('/notification/gestion');
             });
         }


         // RESPONSIVE
         window.addEventListener('resize', () => {
             if (window.innerWidth > MOBILE_BREAKPOINT) {
                 sidebar.classList.remove('mobile-open');
                 if (overlay) overlay.classList.remove('active');
                 updateDesktopToggleIcon();
             }
         });

         // =========================================
         // INITIALISATION
         // =========================================
         initColorPicker();
         renderSidebar();
         renderHeaderMenu();
     </script>
 </body>

 </html>