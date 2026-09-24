# Import source retention

CSV source files are stored on Laravel's private `local` disk. They are not web-addressable and are downloadable only through the authenticated `imports.manage` endpoint.

Deployment policy: retain completed/failed import sources for 90 days unless local regulation requires longer retention. A scheduled deployment job may remove the private file only after recording the import job ID, checksum, deletion timestamp, and actor/job identity. Database preview/error metadata should remain for audit purposes.
