import { Badge } from "@/components/ui/badge";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatMoney, type ValidationIssue } from "@/lib/workbook-types";

export interface TreeData {
  subLabs: { id: string; code: string; name: string }[];
  groups: { id: string; sub_lab_id: string | null; group_name: string }[];
  categories: { id: string; sub_lab_id: string | null; category_name: string }[];
  parameters: {
    id: string;
    sub_lab_id: string | null;
    parameter_group_id: string | null;
    parameter_name: string;
    price_per_test: number | null;
    optional_notes: string | null;
  }[];
  hardware: {
    id: string;
    sub_lab_id: string | null;
    parameter_group_id: string | null;
    hardware_category_id: string | null;
    hardware_name: string;
    price: number | null;
  }[];
}

export function IssueTable({ issues }: { issues: ValidationIssue[] }) {
  if (issues.length === 0) {
    return <p className="text-sm text-muted-foreground">No issues reported.</p>;
  }
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="w-24">Severity</TableHead>
          <TableHead>Sheet</TableHead>
          <TableHead className="w-20">Row</TableHead>
          <TableHead>Field</TableHead>
          <TableHead>Problem</TableHead>
          <TableHead>Suggested action</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {issues.map((issue, i) => (
          <TableRow key={i}>
            <TableCell>
              <Badge variant={issue.severity === "critical" ? "destructive" : "secondary"}>
                {issue.severity}
              </Badge>
            </TableCell>
            <TableCell className="font-mono text-xs">{issue.sheet}</TableCell>
            <TableCell>{issue.row ?? "—"}</TableCell>
            <TableCell>{issue.field ?? "—"}</TableCell>
            <TableCell>{issue.message}</TableCell>
            <TableCell className="text-muted-foreground">{issue.suggestion ?? "—"}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

export function DatasetTreeView({ tree, search = "" }: { tree: TreeData; search?: string }) {
  const q = search.trim().toLowerCase();
  const match = (value: string) => q === "" || value.toLowerCase().includes(q);

  const flatParameters = tree.parameters.filter((p) => p.sub_lab_id === null);

  return (
    <div className="space-y-6">
      {tree.subLabs.length === 0 && flatParameters.length > 0 && (
        <ParameterTable rows={flatParameters.filter((p) => match(p.parameter_name))} />
      )}

      {tree.subLabs.length > 0 && (
        <Accordion type="multiple" className="w-full">
          {tree.subLabs.map((subLab) => {
            const groups = tree.groups.filter((g) => g.sub_lab_id === subLab.id);
            const categories = tree.categories.filter((c) => c.sub_lab_id === subLab.id);
            const hardware = tree.hardware.filter((h) => h.sub_lab_id === subLab.id);
            const params = tree.parameters.filter((p) => p.sub_lab_id === subLab.id);
            return (
              <AccordionItem key={subLab.id} value={subLab.id}>
                <AccordionTrigger className="text-left">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{subLab.name}</span>
                    <Badge variant="outline">{groups.length} groups</Badge>
                    <Badge variant="outline">{params.length} parameters</Badge>
                    <Badge variant="outline">{hardware.length} hardware</Badge>
                    {categories.length > 0 && (
                      <Badge variant="outline">{categories.length} categories</Badge>
                    )}
                  </span>
                </AccordionTrigger>
                <AccordionContent className="space-y-6">
                  {categories.length > 0 && (
                    <section className="space-y-3">
                      <h4 className="text-sm font-semibold">Hardware categories</h4>
                      {categories.map((category) => {
                        const items = hardware.filter(
                          (h) => h.hardware_category_id === category.id && match(h.hardware_name),
                        );
                        if (items.length === 0) return null;
                        return (
                          <div key={category.id} className="rounded-md border p-3">
                            <p className="mb-2 text-sm font-medium">{category.category_name}</p>
                            <HardwareTable rows={items} />
                          </div>
                        );
                      })}
                    </section>
                  )}

                  {groups.map((group) => {
                    const groupParams = params.filter(
                      (p) => p.parameter_group_id === group.id && match(p.parameter_name),
                    );
                    const groupHardware = hardware.filter((h) => h.parameter_group_id === group.id);
                    if (groupParams.length === 0 && groupHardware.length === 0) return null;
                    return (
                      <div key={group.id} className="rounded-md border p-3">
                        <p className="mb-2 text-sm font-medium">
                          Parameter Group: {group.group_name}
                        </p>
                        {groupHardware.length > 0 && (
                          <div className="mb-3">
                            <p className="mb-1 text-xs uppercase text-muted-foreground">
                              Linked hardware ({groupHardware.length})
                            </p>
                            <HardwareTable rows={groupHardware} />
                          </div>
                        )}
                        {groupParams.length > 0 && <ParameterTable rows={groupParams} />}
                      </div>
                    );
                  })}
                </AccordionContent>
              </AccordionItem>
            );
          })}
        </Accordion>
      )}
    </div>
  );
}

function ParameterTable({ rows }: { rows: TreeData["parameters"] }) {
  if (rows.length === 0) return null;
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Parameter / Test</TableHead>
          <TableHead className="w-40">Price per test</TableHead>
          <TableHead>Notes</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map((row) => (
          <TableRow key={row.id}>
            <TableCell>{row.parameter_name}</TableCell>
            <TableCell>
              {row.price_per_test === null ? (
                <Badge variant="destructive">missing</Badge>
              ) : (
                formatMoney(Number(row.price_per_test))
              )}
            </TableCell>
            <TableCell className="text-muted-foreground">{row.optional_notes ?? "—"}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

function HardwareTable({ rows }: { rows: TreeData["hardware"] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Hardware</TableHead>
          <TableHead className="w-40">Price</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map((row) => (
          <TableRow key={row.id}>
            <TableCell>{row.hardware_name}</TableCell>
            <TableCell>
              {row.price === null ? (
                <Badge variant="destructive">missing</Badge>
              ) : (
                formatMoney(Number(row.price))
              )}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
