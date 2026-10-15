<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FlowRotation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE core.campaigns ADD COLUMN flow_rotation text NOT NULL DEFAULT 'position' CHECK (flow_rotation IN ('position', 'weight'))");
        $this->execute("ALTER TABLE core.flows ADD COLUMN flow_type text NOT NULL DEFAULT 'regular' CHECK (flow_type IN ('forced', 'regular', 'default'))");
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
            DO $$ BEGIN
                LOCK TABLE core.campaigns, core.flows IN ACCESS EXCLUSIVE MODE;
                IF EXISTS (SELECT 1 FROM core.campaigns WHERE flow_rotation <> 'position')
                    OR EXISTS (SELECT 1 FROM core.flows WHERE flow_type <> 'regular') THEN
                    RAISE EXCEPTION 'Reset or back up nondefault flow routing settings before downgrade';
                END IF;
                ALTER TABLE core.flows DROP COLUMN flow_type;
                ALTER TABLE core.campaigns DROP COLUMN flow_rotation;
            END $$
            SQL);
    }
}
