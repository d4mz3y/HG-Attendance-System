<?php

return [
  'company_code' => 'HGL',

  'default_branch_code' => 'LA',

  'branch_codes' => [
    'Lagos (HQ)' => 'LA',
    'Abuja' => 'ABJ',
    'Ibadan' => 'IBD',
  ],

  'departments' => [
    'Board of Directors',
    'Management',
    'Operations',
    'Admin',
    'Finance',
    'Security',
  ],

  'department_codes' => [
    'Board of Directors' => 'BOD',
    'Management' => 'MGT',
    'Operations' => 'OPS',
    'Admin' => 'ADM',
    'Finance' => 'FIN',
    'Security' => 'SEC',
  ],

  'staff_id_pattern' => '/^(HGL\/[A-Z]{2}\/[A-Z]{2,4}\/\d{3}|HG-[A-Z0-9-]+)$/',
];
