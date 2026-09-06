<?php

declare(strict_types=1);

namespace SecondStay\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Database\Database;
use SecondStay\I18n\Translator;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\Payment;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentRepository;

/**
 * Reporting (SPECIFICATIONS.md §66).
 *
 * Deux choix de méthode, qui décident de tout le reste :
 *
 * 1. **l'occupation se compte en nuits, pas en séjours.** Un séjour à cheval
 *    sur deux mois compte ses nuits dans chacun, sinon un mois de juillet
 *    plein afficherait zéro parce que le séjour a commencé le 30 juin ;
 * 2. **le chiffre d'affaires se compte à l'encaissement**, avec ce qui est
 *    encore attendu affiché à côté. Mélanger les deux donnerait un total que
 *    ni le comptable ni le propriétaire ne reconnaîtraient.
 *
 * Le rapport ne dit jamais ce qu'il faut déclarer : il compte.
 */
final class ReportService
{
    public function __construct(
        private readonly Database $database,
        private readonly PaymentRepository $payments,
        private readonly Translator $translator,
        private readonly string $currency = 'EUR',
    ) {
    }

    /**
     * Construit le rapport d'une période.
     */
    public function build(ReportPeriod $period): Report
    {
        $received = 0;
        $expected = 0;
        $refunded = 0;
        $deposits = 0;
        $tax = 0;
        $nightsSold = 0;
        $stays = [];

        foreach ($this->overlappingStays($period) as $booking) {
            $nightsInPeriod = $this->nightsInPeriod($booking, $period);
            if ($nightsInPeriod === 0) {
                continue;
            }

            $stayReceived = 0;
            $stayExpected = 0;
            $stayTax = 0;
            $stayHeld = 0;

            foreach ($this->payments->forBooking($booking->id) as $payment) {
                // La caution n'est pas un revenu : elle est détenue, et rendue.
                if ($payment->kind === PaymentKind::SecurityDeposit) {
                    $stayHeld += $this->heldCents($payment);
                    continue;
                }

                if ($payment->kind === PaymentKind::TouristTax) {
                    // La taxe est encaissée pour le compte de la collectivité :
                    // elle est comptée à part, jamais dans le revenu.
                    $stayTax += $payment->netCents();
                    continue;
                }

                $stayReceived += $payment->netCents();
                $stayExpected += $payment->amountCents;
                $refunded += $payment->refundedCents;
            }

            $received += $stayReceived;
            $expected += $stayExpected;
            $tax += $stayTax;
            $deposits += $stayHeld;
            $nightsSold += $nightsInPeriod;

            $stays[] = [
                'reference' => $booking->reference,
                'arrival' => $booking->range->arrival->format('Y-m-d'),
                'departure' => $booking->range->departure->format('Y-m-d'),
                'nights' => $booking->nights(),
                'nights_in_period' => $nightsInPeriod,
                'status' => $booking->status->value,
                'received_cents' => $stayReceived,
                'expected_cents' => $stayExpected,
                'tax_cents' => $stayTax,
                'deposit_held_cents' => $stayHeld,
            ];
        }

        return new Report(
            $period,
            $received,
            $expected,
            $refunded,
            $deposits,
            $tax,
            $nightsSold,
            $period->nights(),
            count($stays),
            $this->currency,
            $stays,
        );
    }

    /**
     * Années pour lesquelles il existe au moins un séjour.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $years = [];
        foreach ($this->database->fetchAll(
            'SELECT DISTINCT YEAR(`arrival`) AS `year` FROM `booking` ORDER BY `year` DESC'
        ) as $row) {
            $years[] = (int) $row['year'];
        }

        if ($years === []) {
            $years[] = (int) gmdate('Y');
        }

        return $years;
    }

    /**
     * Classeur comptable de la période.
     */
    public function workbook(Report $report, string $locale): string
    {
        $writer = new XlsxWriter();

        $writer->addSheet(
            $this->trans('report.sheet.summary', $locale),
            [
                $this->trans('report.field.metric', $locale),
                $this->trans('report.field.value', $locale),
            ],
            [
                [XlsxWriter::text($this->trans('report.period', $locale)), XlsxWriter::text($report->period->label)],
                [XlsxWriter::text($this->trans('report.received', $locale)), XlsxWriter::money($report->receivedCents)],
                [XlsxWriter::text($this->trans('report.expected', $locale)), XlsxWriter::money($report->expectedCents)],
                [
                    XlsxWriter::text($this->trans('report.outstanding', $locale)),
                    XlsxWriter::money($report->outstandingCents()),
                ],
                [XlsxWriter::text($this->trans('report.refunded', $locale)), XlsxWriter::money($report->refundedCents)],
                [
                    XlsxWriter::text($this->trans('report.deposits_held', $locale)),
                    XlsxWriter::money($report->depositsHeldCents),
                ],
                [
                    XlsxWriter::text($this->trans('report.tourist_tax', $locale)),
                    XlsxWriter::money($report->touristTaxCents),
                ],
                [
                    XlsxWriter::text($this->trans('report.nights_sold', $locale)),
                    XlsxWriter::number($report->nightsSold),
                ],
                [
                    XlsxWriter::text($this->trans('report.nights_available', $locale)),
                    XlsxWriter::number($report->nightsAvailable),
                ],
                [
                    XlsxWriter::text($this->trans('report.occupancy', $locale)),
                    XlsxWriter::number($report->occupancyPercent()),
                ],
                [
                    XlsxWriter::text($this->trans('report.average_night', $locale)),
                    XlsxWriter::money($report->averageNightCents()),
                ],
                [XlsxWriter::text($this->trans('report.stays', $locale)), XlsxWriter::number($report->staysCount)],
                // L'avertissement voyage avec le fichier : il ne sert à rien
                // s'il ne vit que sur l'écran qui l'a produit.
                [XlsxWriter::text($this->trans('report.disclaimer', $locale)), XlsxWriter::text('')],
            ]
        );

        $rows = [];
        foreach ($report->stays as $stay) {
            $rows[] = [
                XlsxWriter::text($stay['reference']),
                XlsxWriter::text($stay['arrival']),
                XlsxWriter::text($stay['departure']),
                XlsxWriter::number($stay['nights']),
                XlsxWriter::number($stay['nights_in_period']),
                XlsxWriter::text($this->trans('booking.status.' . $stay['status'], $locale)),
                XlsxWriter::money($stay['received_cents']),
                XlsxWriter::money($stay['expected_cents']),
                XlsxWriter::money($stay['tax_cents']),
                XlsxWriter::money($stay['deposit_held_cents']),
            ];
        }

        $writer->addSheet(
            $this->trans('report.sheet.stays', $locale),
            [
                $this->trans('booking.admin.reference', $locale),
                $this->trans('report.field.arrival', $locale),
                $this->trans('report.field.departure', $locale),
                $this->trans('report.field.nights', $locale),
                $this->trans('report.field.nights_in_period', $locale),
                $this->trans('report.field.status', $locale),
                $this->trans('report.received', $locale),
                $this->trans('report.expected', $locale),
                $this->trans('report.tourist_tax', $locale),
                $this->trans('report.deposits_held', $locale),
            ],
            $rows
        );

        return $writer->output();
    }

    /**
     * Nom de fichier proposé au téléchargement.
     */
    public function filename(Report $report): string
    {
        return 'secondstay-' . $report->period->label . '.xlsx';
    }

    // --- Interne -------------------------------------------------------------------

    /**
     * Séjours dont au moins une nuit tombe dans la période.
     *
     * @return list<Booking>
     */
    private function overlappingStays(ReportPeriod $period): array
    {
        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` '
                . 'WHERE `arrival` <= :window_end AND `departure` > :window_start '
                . 'AND `status` NOT IN (:cancelled, :refused, :hold) '
                . 'ORDER BY `arrival`, `id`',
                [
                    'window_start' => $period->from,
                    'window_end' => $period->to,
                    'cancelled' => 'cancelled',
                    'refused' => 'refused',
                    'hold' => 'hold',
                ]
            )
        );
    }

    /**
     * Nuits d'un séjour qui tombent dans la période.
     *
     * La nuit du départ n'est pas occupée : elle ne compte pas.
     */
    private function nightsInPeriod(Booking $booking, ReportPeriod $period): int
    {
        $nights = 0;
        $day = new DateTimeImmutable(
            $booking->range->arrival->format('Y-m-d') . ' 00:00:00',
            new DateTimeZone('UTC')
        );
        $departure = $booking->range->departure->format('Y-m-d');

        while ($day->format('Y-m-d') < $departure) {
            if ($period->contains($day->format('Y-m-d'))) {
                $nights++;
            }

            $day = $day->modify('+1 day');
        }

        return $nights;
    }

    /**
     * Montant de caution réellement détenu.
     *
     * Une caution rendue n'est plus détenue ; une caution partiellement
     * retenue l'est pour ce qui n'a pas été rendu.
     */
    private function heldCents(Payment $payment): int
    {
        return match ($payment->holdStatus) {
            HoldStatus::Received, HoldStatus::ToReturn => $payment->netCents(),
            HoldStatus::PartiallyRetained => $payment->netCents(),
            default => 0,
        };
    }

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], $locale);
    }
}
