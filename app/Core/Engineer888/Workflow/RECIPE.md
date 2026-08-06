# Recipe: run an engineering task through the lifecycle

Every task flows through fourteen stages. Nothing bypasses them.

    php artisan engineering:task --register-project
    php artisan engineering:task --create --title="..." --description="..." --change-set=cs.json
    php artisan engineering:task --run=<uuid> --dry-run
    php artisan engineering:task --approve=<uuid> --by="<person>"
    php artisan engineering:task --run=<uuid>
    php artisan engineering:task --status=<uuid>

The workflow halts at the first stage that blocks or fails. IMPLEMENT never runs
without an approver recorded on the task, and IDENTIFY_FILES refuses any file the
active sprint manifest cannot prove it owns.

A dry run skips IMPLEMENT, DEPLOY_VERIFY and PROMOTE, because those write.
