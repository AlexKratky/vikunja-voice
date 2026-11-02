<?php

return [
    'transcript_label' => 'Transcript',
    'transcript_label_multi' => 'Transcript (task :index/:total)',
    'fallback_title' => 'Recording :time',
    'processing_failed' => 'Voice note processing failed',
    'kuma_failed_jobs' => '{1} :count recording failed, see failed_jobs|[2,*] :count recordings failed, see failed_jobs',

    // Blade-only strings.
    'ui' => [
        'token_label' => 'Enter upload token',
        'save' => 'Save',
        'seek' => 'Position in recording',
        'send' => 'Send',
        'discard' => 'Discard',
        'history' => 'History',
        'history_note' => 'only in this browser',
        'delete_all' => 'Delete all',
    ],

    // Passed to public/js/app.js via window.VV; keys shared with the
    // initial blade render (status text, aria labels) live here too.
    'js' => [
        'ready' => 'Ready',
        'recording' => 'Recording… (tap to stop)',
        'review' => 'Check and send',
        'sending' => 'Sending…',
        'sent' => 'Sent ✓ (will be processed in the background)',
        'send_error' => 'Upload error',
        'send_error_hint' => 'you can resend it from the history',
        'mic_error' => 'Microphone unavailable',
        'invalid_token' => 'Invalid token',
        'empty_history' => 'No recordings yet',
        'badge_sent' => 'sent',
        'badge_unsent' => 'not sent',
        'badge_error' => 'error',
        'retry' => 'Retry',
        'send' => 'Send',
        'confirm_resend_duplicate' => 'This recording was already sent. Send again anyway? That may create a duplicate task.',
        'resent' => 'Resent ✓ (will be processed in the background)',
        'import' => 'Or import audio',
        'import_error' => 'Could not import this file',
        'play_recording' => 'Play recording',
        'stop_playback' => 'Stop playback',
        'play' => 'Play',
        'pause' => 'Pause',
        'playback_speed' => 'Playback speed',
        'seek' => 'Position in recording',
        'record' => 'Record',
        'stop_recording' => 'Stop recording',
        'share_recording' => 'Share recording',
        'download_recording' => 'Download recording',
        'share_target_error' => 'Could not receive the shared file. Open the app and share it again.',
        'delete_recording' => 'Delete recording',
        'confirm_delete' => 'Delete this recording?',
        'confirm_delete_all' => 'Delete the entire history?',
    ],
];
