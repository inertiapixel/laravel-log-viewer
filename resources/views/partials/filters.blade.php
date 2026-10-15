@php
    $filters = $vm['filters'];
    $errors = $vm['errors'];
    $describedBy = fn (string $field, string ...$extra) => trim(implode(' ', array_merge(isset($errors[$field]) ? ['ipx-lv-error-'.$field] : [], $extra)));
@endphp
<form class="ipx-log-viewer__card ipx-log-viewer__filters" method="get" action="{{ $vm['routes']['index'] }}" role="search" aria-label="{{ $t('filters.heading') }}" data-ipx-lv-form novalidate>
    <div class="ipx-log-viewer__filters-grid">
        @if ($vm['enabled']['search'])
            <div class="ipx-log-viewer__field ipx-log-viewer__field--search">
                <label class="ipx-log-viewer__label" for="ipx-lv-q">{{ $t('fields.q') }}</label>
                <div class="ipx-log-viewer__input-group">
                    @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'search'])
                    <input class="ipx-log-viewer__input ipx-log-viewer__input--search" type="search" id="ipx-lv-q" name="q"
                           value="{{ $filters->search }}" maxlength="{{ $vm['searchMaxLength'] }}"
                           placeholder="{{ $t('filters.search_placeholder') }}" autocomplete="off" spellcheck="false"
                           @if ($describedBy('q')) aria-describedby="{{ $describedBy('q') }}" aria-invalid="true" @endif>
                </div>
                <div class="ipx-log-viewer__checks">
                    @if ($vm['enabled']['caseToggle'])
                        <label class="ipx-log-viewer__check"><input type="checkbox" class="ipx-log-viewer__checkbox" name="case" value="1" @checked($filters->caseSensitive)> {{ $t('filters.case_sensitive') }}</label>
                    @endif
                    @if ($vm['enabled']['traceToggle'])
                        <label class="ipx-log-viewer__check"><input type="checkbox" class="ipx-log-viewer__checkbox" name="traces" value="1" @checked($filters->searchTraces)> {{ $t('filters.search_traces') }}</label>
                    @endif
                    @if ($vm['enabled']['regex'])
                        <label class="ipx-log-viewer__check"><input type="checkbox" class="ipx-log-viewer__checkbox" name="regex" value="1" @checked($filters->regex)> {{ $t('filters.regex') }}</label>
                    @endif
                </div>
                @isset($errors['q'])<p class="ipx-log-viewer__field-error" id="ipx-lv-error-q">{{ $errors['q'] }}</p>@endisset
            </div>
        @endif

        @if ($vm['enabled']['dateRange'])
            <fieldset class="ipx-log-viewer__field ipx-log-viewer__fieldset ipx-log-viewer__date-range" data-ipx-lv-date-range>
                <legend class="ipx-log-viewer__label">{{ $t('fields.preset') }}</legend>
                <div class="ipx-log-viewer__date-row">
                    <label class="ipx-log-viewer__sr-only" for="ipx-lv-preset">{{ $t('fields.preset') }}</label>
                    <select class="ipx-log-viewer__select" id="ipx-lv-preset" name="preset" data-ipx-lv-preset aria-describedby="ipx-lv-date-hint">
                        @foreach ($vm['presets'] as $key => $label)
                            <option value="{{ $key }}" @selected($filters->preset === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="ipx-log-viewer__date-inputs" data-ipx-lv-dates>
                        <div class="ipx-log-viewer__date-pair">
                            <label class="ipx-log-viewer__date-label" for="ipx-lv-from">{{ $t('fields.from') }}</label>
                            <input class="ipx-log-viewer__input ipx-log-viewer__input--date" type="date" id="ipx-lv-from" name="from" value="{{ $filters->fromDate }}"
                                   min="1970-01-02" max="2100-01-01" data-ipx-lv-from
                                   @if ($describedBy('from')) aria-describedby="{{ $describedBy('from') }}" aria-invalid="true" @endif>
                        </div>
                        <div class="ipx-log-viewer__date-pair">
                            <label class="ipx-log-viewer__date-label" for="ipx-lv-to">{{ $t('fields.to') }}</label>
                            <input class="ipx-log-viewer__input ipx-log-viewer__input--date" type="date" id="ipx-lv-to" name="to" value="{{ $filters->toDate }}"
                                   min="1970-01-02" max="2100-01-01" data-ipx-lv-to
                                   @if ($describedBy('to')) aria-describedby="{{ $describedBy('to') }}" aria-invalid="true" @endif>
                        </div>
                        <button type="button" class="ipx-log-viewer__button ipx-log-viewer__button--secondary ipx-log-viewer__button--icon" data-ipx-lv-calendar-toggle
                                aria-haspopup="dialog" aria-expanded="false" aria-controls="ipx-lv-calendar" aria-label="{{ $t('filters.open_calendar') }}" title="{{ $t('filters.open_calendar') }}" hidden>
                            @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'calendar'])
                        </button>
                    </div>
                </div>
                <div class="ipx-log-viewer__popover" id="ipx-lv-calendar" role="dialog" aria-modal="false" aria-labelledby="ipx-lv-calendar-title" data-ipx-lv-calendar-popover hidden>
                    <p class="ipx-log-viewer__popover-title" id="ipx-lv-calendar-title">{{ $t('filters.calendar_title') }}</p>
                    <div class="ipx-log-viewer__popover-body">
                        <ul class="ipx-log-viewer__preset-list" data-ipx-lv-preset-list></ul>
                        <div class="ipx-log-viewer__calendar" data-ipx-lv-calendar></div>
                    </div>
                    <p class="ipx-log-viewer__hint" data-ipx-lv-calendar-summary aria-live="polite">{{ $t('filters.calendar_hint') }}</p>
                    <div class="ipx-log-viewer__popover-actions">
                        <button type="button" class="ipx-log-viewer__button ipx-log-viewer__button--ghost" data-ipx-lv-calendar-clear>{{ $t('filters.calendar_clear') }}</button>
                        <span class="ipx-log-viewer__spacer"></span>
                        <button type="button" class="ipx-log-viewer__button ipx-log-viewer__button--secondary" data-ipx-lv-calendar-cancel>{{ $t('filters.calendar_cancel') }}</button>
                        <button type="button" class="ipx-log-viewer__button ipx-log-viewer__button--primary" data-ipx-lv-calendar-apply>{{ $t('filters.calendar_apply') }}</button>
                    </div>
                </div>
                <p class="ipx-log-viewer__hint" id="ipx-lv-date-hint" data-ipx-lv-date-hint>
                    @if ($vm['dateHint'])<span data-ipx-lv-date-range-text>{{ $vm['dateHint'] }}.</span>@endif
                    <span data-ipx-lv-custom-hint>{{ $t('filters.custom_hint') }}</span>
                </p>
                <p class="ipx-log-viewer__field-error" id="ipx-lv-error-dates" data-ipx-lv-date-error @unless(isset($errors['from']) || isset($errors['to'])) hidden @endunless>
                    @isset($errors['from'])<span id="ipx-lv-error-from">{{ $errors['from'] }}</span>@endisset
                    @isset($errors['to'])<span id="ipx-lv-error-to">{{ $errors['to'] }}</span>@endisset
                </p>
            </fieldset>
        @endif

        @if ($vm['enabled']['files'])
            <div class="ipx-log-viewer__field">
                <label class="ipx-log-viewer__label" for="ipx-lv-file">{{ $t('fields.file') }}</label>
                <select class="ipx-log-viewer__select" id="ipx-lv-file" name="file" @if ($describedBy('file')) aria-describedby="{{ $describedBy('file') }}" aria-invalid="true" @endif>
                    <option value="all">{{ $t('filters.all_files') }} ({{ count($vm['files']) }})</option>
                    @foreach ($vm['files'] as $file)
                        <option value="{{ $file->id }}" @selected($filters->file === $file->id) @disabled(! $file->readable)>
                            {{ $file->name }} — {{ \InertiaPixel\LaravelLogViewer\Query\LogQuery::bytes($file->size) }}@unless ($file->readable) ({{ $t('filters.unreadable') }})@endunless
                        </option>
                    @endforeach
                </select>
                @isset($errors['file'])<p class="ipx-log-viewer__field-error" id="ipx-lv-error-file">{{ $errors['file'] }}</p>@endisset
            </div>
        @endif

        @if ($vm['enabled']['channels'])
            <div class="ipx-log-viewer__field" data-ipx-lv-channel-field @if (count($vm['channels']) < 2 && $filters->channel === null) hidden @endif>
                <label class="ipx-log-viewer__label" for="ipx-lv-channel">{{ $t('fields.channel') }}</label>
                <select class="ipx-log-viewer__select" id="ipx-lv-channel" name="channel" data-ipx-lv-channel>
                    <option value="">{{ $t('filters.all_channels') }}</option>
                    @foreach ($vm['channels'] as $channel)
                        <option value="{{ $channel }}" @selected($filters->channel === $channel)>{{ $channel }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="ipx-log-viewer__field">
            <label class="ipx-log-viewer__label" for="ipx-lv-sort">{{ $t('fields.sort') }}</label>
            <select class="ipx-log-viewer__select" id="ipx-lv-sort" name="sort">
                @foreach ($vm['sorts'] as $sort)
                    <option value="{{ $sort }}" @selected($filters->sort === $sort)>{{ $t('sorts.'.$sort) }}</option>
                @endforeach
            </select>
        </div>

        <div class="ipx-log-viewer__field">
            <label class="ipx-log-viewer__label" for="ipx-lv-per-page">{{ $t('fields.per_page') }}</label>
            <select class="ipx-log-viewer__select" id="ipx-lv-per-page" name="per_page">
                @foreach ($vm['perPageOptions'] as $option)
                    <option value="{{ $option }}" @selected($filters->perPage === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($vm['enabled']['levels'])
        <fieldset class="ipx-log-viewer__fieldset ipx-log-viewer__levels" aria-describedby="ipx-lv-levels-hint">
            <legend class="ipx-log-viewer__label">{{ $t('fields.levels') }}</legend>
            <div class="ipx-log-viewer__level-options">
                @foreach ($vm['levels'] as $level)
                    <label class="ipx-log-viewer__level-option ipx-log-viewer__level-option--{{ $level }}">
                        <input type="checkbox" class="ipx-log-viewer__checkbox" name="levels[]" value="{{ $level }}" @checked(in_array($level, $filters->levels, true))>
                        <span>{{ $t('levels.'.$level) }}</span>
                    </label>
                @endforeach
            </div>
            <p class="ipx-log-viewer__hint" id="ipx-lv-levels-hint">{{ $t('filters.levels_hint') }}</p>
        </fieldset>
    @endif

    <div class="ipx-log-viewer__form-actions">
        <button type="submit" class="ipx-log-viewer__button ipx-log-viewer__button--primary">{{ $t('filters.apply') }}</button>
        <a class="ipx-log-viewer__button ipx-log-viewer__button--secondary" href="{{ $vm['resetUrl'] }}" data-ipx-lv-nav data-ipx-lv-reset>{{ $t('filters.reset') }}</a>
    </div>
</form>
