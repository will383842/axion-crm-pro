\set off random(1, 40000)
SET app.current_workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168';
SELECT * FROM activities WHERE workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168'::uuid
  AND person_key = (SELECT person_key FROM contacts ORDER BY id OFFSET :off LIMIT 1)
ORDER BY occurred_at DESC LIMIT 100;
