jQuery(function ($) {

    var chart_html_id = 'wio-main-chart';
    var webp_chart_html_id = 'wio-webp-chart';
    var avif_chart_html_id = 'wio-avif-chart';

    var ctx = document.getElementById(chart_html_id);
    var ctx_webp = document.getElementById(webp_chart_html_id);
    var ctx_avif = document.getElementById(avif_chart_html_id);

    window.wio_chart = new window.robin.Chart(ctx, {
        type: 'doughnut',
        data: {
            datasets: [
                {
                    data: [
                        $('#' + chart_html_id).attr('data-errors'),
                        $('#' + chart_html_id).attr('data-optimized'),
                        $('#' + chart_html_id).attr('data-unoptimized'),
                    ],
                    backgroundColor: [
                        '#f1b1b6',
                        '#8bc34a',
                        '#d6d6d6',
                    ],
                    borderWidth: 0,
                    label: 'Dataset 1'
                }
            ]
        },
        options: {
            legend: {
                display: false
            },
            events: [],
            animation: {
                easing: 'easeOutBounce'
            },
            responsive: false,
            cutoutPercentage: 80
        }
    });

    if (ctx_webp) {
        window.wio_chart_webp = new window.robin.Chart(ctx_webp, {
            type: 'doughnut',
            data: {
                datasets: [
                    {
                        data: [
                            $('#' + webp_chart_html_id).attr('data-errors'),
                            $('#' + webp_chart_html_id).attr('data-optimized'),
                            $('#' + webp_chart_html_id).attr('data-unoptimized'),
                        ],
                        backgroundColor: [
                            '#f1b1b6',
                            '#8bc34a',
                            '#d6d6d6',
                        ],
                        borderWidth: 0,
                        label: 'Dataset 1'
                    }
                ]
            },
            options: {
                legend: {
                    display: false
                },
                events: [],
                animation: {
                    easing: 'easeOutBounce'
                },
                responsive: false,
                cutoutPercentage: 80
            }
        });
    }

    if (ctx_avif) {
        window.wio_chart_avif = new window.robin.Chart(ctx_avif, {
            type: 'doughnut',
            data: {
                datasets: [
                    {
                        data: [
                            $('#' + avif_chart_html_id).attr('data-errors'),
                            $('#' + avif_chart_html_id).attr('data-optimized'),
                            $('#' + avif_chart_html_id).attr('data-unoptimized'),
                        ],
                        backgroundColor: [
                            '#f1b1b6',
                            '#8bc34a',
                            '#d6d6d6',
                        ],
                        borderWidth: 0,
                        label: 'Dataset 1'
                    }
                ]
            },
            options: {
                legend: {
                    display: false
                },
                events: [],
                animation: {
                    easing: 'easeOutBounce'
                },
                responsive: false,
                cutoutPercentage: 80
            }
        });
    }
});
