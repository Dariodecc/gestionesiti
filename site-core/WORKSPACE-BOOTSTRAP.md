# Project Workspace bootstrap
SiteCore 2.12.3 extends the existing MCP server with bootstrap_project and automatic persistent OpenCode setup.
Arguments: phase (brief, seo, design, development/code, qa), optional task_id.
The site id comes ONLY from the existing encrypted binding; callers cannot select another site.
Existing SCC_Developer_Permissions and HUB account policy remain mandatory.
The client calls GET /scchub/v1/developer/sites/{bound_site_id}/bootstrap.
No local project memory or skill registry is created.
Deploy the HUB Workspace extension before updating a client.
OpenCode setup uses a ten-minute single-use installation grant; the resulting credential is permanent and stored encrypted by the VPS bridge.
