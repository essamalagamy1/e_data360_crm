<?php

/*
|--------------------------------------------------------------------------
| The words employees read
|--------------------------------------------------------------------------
| Everything here can be edited freely. After editing on the server, open
| /config-cache while logged in as the company owner.
|
| The lines are written so they read correctly for men and women alike: Arabic
| second-person suffixes without diacritics are the same for both, so avoid
| present-tense verbs and adjectives addressed to the reader.
|
| Each person gets one line per day. It stays the same if they reload, and a
| colleague usually sees a different one.
*/

return [

    // The thank-you sentence at the top of the employee's page.
    'thanks' => [
        'شكراً لوجودك معنا. نحن شركاء في نجاح E-Data 360.',
        'كل إنجاز في E-Data 360 بدأ بمجهود مثل مجهودك. شكراً لك.',
        'نجاح الشركة يُكتب باسمك واسم زملائك. شكراً لأنك معنا.',
        'ثقة عملائنا بُنيت بمجهودك. شكراً لك، فنحن شركاء نجاح.',
        'ما وصلنا إليه اليوم كان بفضل الله ثم بفضل فريقنا. شكراً لأنك منه.',
        'شكراً لأنك اخترت أن تبني هذا النجاح معنا.',
        'كل مهمة منجزة تقرّبنا خطوة، وأنت جزء من كل خطوة. شكراً لك.',
    ],

    // Shown under «توكلنا على الله» when the workday starts.
    'start_duas' => [
        'بسم الله، توكلت على الله، ولا حول ولا قوة إلا بالله.',
        'اللهم إني أسألك خير هذا اليوم: فتحه ونصره ونوره وبركته وهداه.',
        'اللهم إني أسألك علماً نافعاً، ورزقاً طيباً، وعملاً متقبلاً.',
        'اللهم لا سهل إلا ما جعلته سهلاً، وأنت تجعل الحزن إذا شئت سهلاً.',
        'رب اشرح لي صدري، ويسر لي أمري.',
        'اللهم بارك لنا في يومنا هذا، وفي رزقنا وعملنا.',
        'يا حي يا قيوم برحمتك أستغيث، أصلح لي شأني كله.',
        'رب زدني علماً.',
    ],

    // Shown on the thank-you screen when the workday ends.
    'end_duas' => [
        'بارك الله في وقتك وجهدك ورزقك.',
        'جزاك الله خيراً على تعبك اليوم.',
        'أعطاك الله العافية، وجعل هذا الجهد في ميزان حسناتك.',
        'الحمد لله الذي بنعمته تتم الصالحات.',
        'إنا لا نضيع أجر من أحسن عملاً.',
        'رافقتك السلامة في طريقك إلى بيتك.',
    ],

    // Ready-made lines in the «كلمة شكر» box. People can also write their own.
    'kudos_presets' => [
        'شكراً على مساعدتك اليوم.',
        'شغلك كان ممتاز في هذا المشروع.',
        'وجودك في الفريق فرق معنا.',
        'شكراً لأنك وقفت معي وقت الضغط.',
    ],

    // Completed-task counts that get a progress bar on the employee's page.
    'task_milestones' => [10, 25, 50, 100, 250, 500, 1000, 2000, 5000],

    // How many thank-you notes one person can send in a day.
    'kudos_daily_limit' => 10,
];
