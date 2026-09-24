/**
 * Converts birth data between the API's shape and the form's editing state.
 * The form tracks how the location was given: a place from the list ("place"),
 * typed by hand ("manual"), or not yet ("none").
 */

export function emptyBirth() {
    return {
        birth_date: '',
        birth_time: '',
        time_accuracy: 'exact',
        mode: 'none',
        place: null,
        birth_place: '',
        birth_country_code: '',
        latitude: '',
        longitude: '',
        birth_timezone: '',
        data_source: '',
        notes: '',
    };
}

export function birthFromApi(birth) {
    if (!birth) return emptyBirth();

    const fromList = birth.geocode_source === 'geonames' && birth.place_id !== null;
    const manual = birth.geocode_source === 'manual';

    return {
        ...emptyBirth(),
        birth_date: birth.birth_date ?? '',
        birth_time: birth.birth_time ?? '',
        time_accuracy: birth.time_accuracy,
        mode: fromList ? 'place' : manual ? 'manual' : 'none',
        place: fromList
            ? {
                  id: String(birth.place_id),
                  label: birth.birth_place,
                  country_code: birth.birth_country_code,
                  latitude: birth.latitude,
                  longitude: birth.longitude,
                  timezone: birth.birth_timezone,
              }
            : null,
        birth_place: birth.birth_place ?? '',
        birth_country_code: birth.birth_country_code ?? '',
        latitude: birth.latitude ?? '',
        longitude: birth.longitude ?? '',
        birth_timezone: birth.birth_timezone ?? '',
        data_source: birth.data_source ?? '',
        notes: birth.notes ?? '',
    };
}

/** Whether anything has been entered, so an empty section is not sent. */
export function hasBirthInput(state) {
    return Boolean(state.birth_date || state.birth_time || state.place || state.latitude !== '' || state.birth_place);
}

export function birthToPayload(state) {
    const nullable = (value) => (value === '' || value === undefined ? null : value);

    const payload = {
        time_accuracy: state.time_accuracy,
        birth_date: nullable(state.birth_date),
        birth_time: state.time_accuracy === 'unknown' ? null : nullable(state.birth_time),
        data_source: nullable(state.data_source),
        notes: nullable(state.notes),
    };

    if (state.mode === 'place' && state.place) {
        payload.place_id = Number(state.place.id);
    } else if (state.mode === 'manual') {
        Object.assign(payload, {
            birth_place: nullable(state.birth_place),
            birth_country_code: nullable(state.birth_country_code),
            latitude: nullable(state.latitude),
            longitude: nullable(state.longitude),
            birth_timezone: nullable(state.birth_timezone),
        });
    }

    return payload;
}
